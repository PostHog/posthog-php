"""Exercise shell orchestration with fake Composer/gh, never credentials or publication."""

import json
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest

from test_laravel_release import LaravelReleaseFixture, REPO, SCRIPT, git, snapshot


class LaravelWorkflowScriptsTest(LaravelReleaseFixture):
    def executable(self, name, text):
        path = self.directory / "bin" / name
        path.parent.mkdir(exist_ok=True)
        path.write_text(text)
        path.chmod(0o755)
        return path

    def test_composer_overrides_and_example_tests_run_only_in_temporary_copy(self):
        self.write("examples/laravel/composer.json", '{"name":"posthog/laravel-example","require":{},"scripts":{"test":"php artisan test"},"config":{"platform":{"php":"8.2.0"}}}')
        self.write(".gitattributes", "/packages/laravel export-ignore\n")
        self.write(".changeset/core.md", '---\n"posthog-php": patch\n---\n\nCore change\n')
        snapshot(self.root)
        candidate = self.prepare()
        self.write("packages/laravel/.env", "local-secret")
        self.write("packages/laravel/vendor/local.txt", "local vendor")
        log = self.directory / "composer.log"
        self.executable("composer", '''#!/usr/bin/env python3
import json, os, pathlib, sys
root = pathlib.Path.cwd()
with open(os.environ['COMPOSER_TEST_LOG'], 'a') as log:
    log.write(json.dumps({'cwd': str(root), 'args': sys.argv[1:], 'metadata': json.loads((root / 'composer.json').read_text())}) + '\\n')
if sys.argv[1] == 'update':
    executable = root / 'vendor/bin/phpunit'
    executable.parent.mkdir(parents=True, exist_ok=True)
    executable.write_text('#!/bin/sh\\nexit 0\\n')
    executable.chmod(0o755)
''')
        env = {**os.environ, "PATH": f"{self.directory / 'bin'}:{os.environ['PATH']}", "COMPOSER_TEST_LOG": str(log)}
        before = git(self.root, "status", "--porcelain")
        subprocess.run(["bash", str(SCRIPT.with_name("test-laravel.sh")), "13", str(candidate / "release.patch")], cwd=self.root, env=env, check=True, capture_output=True)
        self.assertEqual(before, git(self.root, "status", "--porcelain"))
        self.assertEqual((self.root / "packages/laravel/.env").read_text(), "local-secret")
        self.assertEqual((self.root / "packages/laravel/vendor/local.txt").read_text(), "local vendor")
        entries = [json.loads(line) for line in log.read_text().splitlines()]
        self.assertTrue(any(e["args"] == ["run", "test"] for e in entries))
        self.assertTrue(any("orchestra/testbench:^11.0" in e["args"] for e in entries))
        self.assertTrue(any("laravel/framework:^13.0" in e["args"] for e in entries))
        for entry in entries:
            self.assertNotEqual(Path(entry["cwd"]), self.root / "packages/laravel")
            for repository in entry["metadata"]["repositories"]:
                self.assertFalse(Path(repository["url"]).exists(), "Temporary dependency copy should be cleaned up")
        example_entries = [e for e in entries if e['cwd'].endswith('/examples/laravel')]
        self.assertTrue(example_entries)
        self.assertTrue(all(e['metadata']['config']['platform']['php'] == '8.3.0' for e in example_entries))
        self.assertEqual(json.loads((self.root / 'examples/laravel/composer.json').read_text())['config']['platform']['php'], '8.2.0')
        self.assertNotIn("repositories", json.loads((self.root / "packages/laravel/composer.json").read_text()))
        self.assertTrue(all(e["metadata"]["repositories"][0]["options"]["versions"]["posthog/posthog-php"] == "4.20.1" for e in entries))
        self.assertTrue(all(e["metadata"]["require"]["posthog/posthog-laravel"] == "0.1.1" for e in example_entries))
        self.assertEqual(json.loads((self.root / "package.json").read_text())["version"], "4.20.0")


class ProductionDependencyTest(LaravelReleaseFixture):
    def test_production_install_waits_for_the_exact_published_core(self):
        candidate = self.prepare()
        workflow = (REPO / ".github/workflows/release.yml").read_text()
        step = workflow.split('      - name: Wait for the exact published core and test production dependency resolution\n', 1)[1]
        script = textwrap.dedent(step.split('        run: |\n', 1)[1].split('      - name: Require signed source commit', 1)[0])
        curl = self.bin / 'curl'
        curl.write_text('''#!/usr/bin/env python3
import json, os, pathlib, sys
path = pathlib.Path(sys.argv[sys.argv.index('-o') + 1])
path.write_text(json.dumps({'packages': {'posthog/posthog-php': [{'version': os.environ['PUBLISHED_CORE']}]}}))
''')
        composer = self.bin / 'composer'
        composer.write_text('''#!/usr/bin/env python3
import os, pathlib, sys
assert '--no-dev' in sys.argv and '--no-scripts' in sys.argv and '--no-plugins' in sys.argv
pathlib.Path(os.environ['INSTALL_MARKER']).touch()
''')
        sleep = self.bin / 'sleep'
        sleep.write_text('#!/bin/sh\nexit 0\n')
        for executable in [curl, composer, sleep]:
            executable.chmod(0o755)
        for published, success in [('4.19.0', False), ('4.20.0', True)]:
            with self.subTest(published=published):
                root = self.directory / published
                root.mkdir()
                (root / 'candidate').symlink_to(candidate, target_is_directory=True)
                marker = root / 'installed'
                result = subprocess.run(['bash', '-c', script], env={
                    **os.environ, 'PATH': f'{self.bin}:{os.environ["PATH"]}', 'RUNNER_TEMP': str(root),
                    'CORE_VERSION': '4.20.0', 'PUBLISHED_CORE': published, 'INSTALL_MARKER': str(marker),
                }, capture_output=True, text=True)
                self.assertEqual(result.returncode == 0, success, result.stderr)
                self.assertEqual(marker.exists(), success)


class ReleaseWorkflowTest(unittest.TestCase):
    def test_repository_declares_the_dependency_and_matching_exact_core_pin(self):
        core = json.loads((REPO / 'package.json').read_text())
        laravel = json.loads((REPO / 'packages/laravel/package.json').read_text())
        composer = json.loads((REPO / 'packages/laravel/composer.json').read_text())
        self.assertEqual(laravel['dependencies']['posthog-php'], 'workspace:*')
        self.assertEqual(composer['require']['posthog/posthog-php'], core['version'])

    def test_one_shared_source_commit_precedes_separate_package_publication(self):
        self.assertFalse((REPO / ".github/workflows/laravel-release.yml").exists())
        workflow = (REPO / ".github/workflows/release.yml").read_text()
        self.assertEqual(workflow.count("--mode source"), 1)
        self.assertIn("    needs: [prepare-release-candidate, release]\n", workflow)
        publish_gate = workflow.split("  publish-laravel:\n", 1)[1].split("    runs-on:", 1)[0]
        self.assertIn("if: always() && !cancelled() && needs.release.result == 'success'", publish_gate)
        self.assertIn("needs.release.outputs.commit-hash", workflow)
        self.assertIn("needs.prepare-release-candidate.outputs.core-changed == 'true'", workflow)
        self.assertNotIn("--filter", workflow)
        self.assertIn("Core $CORE_VERSION is not available on Packagist; Laravel was not published", workflow)


class ReleaseChangeCheckTest(LaravelReleaseFixture):
    def check(self, trigger=None):
        head = git(self.root, "rev-parse", "HEAD").decode().strip()
        git(self.root, "update-ref", "refs/remotes/origin/main", head)
        output = self.directory / "outputs"
        output.unlink(missing_ok=True)
        result = subprocess.run(
            ["bash", str(SCRIPT.with_name("check-release-changes.sh"))],
            cwd=self.root,
            env={**os.environ, "TRIGGER_SHA": trigger or head, "GITHUB_OUTPUT": str(output)},
            capture_output=True, text=True,
        )
        return result, output.read_text() if output.exists() else ""

    def test_check_uses_the_dependency_graph_and_does_not_reconsume_intents(self):
        (self.root / ".changeset/release.md").unlink()
        self.write(".changeset/core.md", '---\n"posthog-php": patch\n---\n\nCore change\n')
        snapshot(self.root)
        result, output = self.check()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("has-changesets=true", output)
        self.assertEqual(json.loads((self.root / "package.json").read_text())["version"], "4.20.1")
        self.assertEqual(json.loads((self.root / "packages/laravel/package.json").read_text())["version"], "0.1.1")
        snapshot(self.root)
        result, output = self.check()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("has-changesets=false", output)

    def test_stale_runs_fail_with_pending_intents_and_skip_without_them(self):
        trigger = git(self.root, "rev-parse", "HEAD").decode().strip()
        self.write("new.txt", "main moved")
        snapshot(self.root)
        result, _ = self.check(trigger)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("main moved", result.stderr)
        snapshot(self.root)
        git(self.root, "checkout", "--quiet", "main")
        result, output = self.check(trigger)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("has-changesets=false", output)


class ReleaseRefCheckTest(unittest.TestCase):
    def test_missing_refs_pass_but_network_permissions_existing_refs_and_same_repo_fail(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            gh = directory / "gh"
            gh.write_text('''#!/usr/bin/env python3
import os, sys
endpoint = sys.argv[2]
if endpoint.endswith('/git/ref/heads/main'):
    print('a' * 40)
elif endpoint == 'repos/PostHog/posthog-laravel':
    print('false')
elif os.environ['REF_RESULT'] == 'core-existing' and endpoint.endswith('/4.20.1'):
    print('{}')
elif os.environ['REF_RESULT'] in ['missing', 'core-existing']:
    print('gh: Not Found (HTTP 404)', file=sys.stderr)
    sys.exit(1)
elif os.environ['REF_RESULT'] == 'forbidden':
    print('gh: Forbidden (HTTP 403)', file=sys.stderr)
    sys.exit(1)
else:
    print('{}')
''')
            gh.chmod(0o755)
            env = {**os.environ, "PATH": f"{directory}:{os.environ['PATH']}", "SOURCE_REPOSITORY": "PostHog/posthog-php", "DISTRIBUTION_REPOSITORY": "PostHog/posthog-laravel", "VERSION": "0.1.0", "REF_RESULT": "missing"}
            command = ["bash", str(SCRIPT.with_name("check-release-refs.sh"))]
            self.assertEqual(subprocess.run(command, env=env, capture_output=True).returncode, 0)
            env.update(CORE_CHANGED='true', CORE_VERSION='4.20.1')
            self.assertEqual(subprocess.run(command, env=env, capture_output=True).returncode, 0)
            self.assertNotEqual(subprocess.run(command, env={**env, 'REF_RESULT': 'core-existing'}, capture_output=True).returncode, 0)
            for result in ["forbidden", "existing"]:
                self.assertNotEqual(subprocess.run(command, env={**env, "REF_RESULT": result}, capture_output=True).returncode, 0)
            self.assertNotEqual(subprocess.run(command, env={**env, "DISTRIBUTION_REPOSITORY": "posthog/POSTHOG-PHP"}, capture_output=True).returncode, 0)
