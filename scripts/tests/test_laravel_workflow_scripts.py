"""Exercise shell orchestration with fake Composer/gh, never credentials or publication."""

import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

from test_laravel_release import LaravelReleaseFixture, SCRIPT, git, snapshot


class LaravelWorkflowScriptsTest(LaravelReleaseFixture):
    def executable(self, name, text):
        path = self.directory / "bin" / name
        path.parent.mkdir(exist_ok=True)
        path.write_text(text)
        path.chmod(0o755)
        return path

    def test_composer_overrides_and_example_tests_run_only_in_temporary_copy(self):
        self.write("examples/laravel/composer.json", '{"name":"posthog/laravel-example","scripts":{"test":"php artisan test"}}')
        snapshot(self.root)
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
        subprocess.run(["bash", str(SCRIPT.with_name("test-laravel.sh")), "13"], cwd=self.root, env=env, check=True, capture_output=True)
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
        self.assertNotIn("repositories", json.loads((self.root / "packages/laravel/composer.json").read_text()))


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
elif os.environ['REF_RESULT'] == 'missing':
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
            command = ["bash", str(SCRIPT.with_name("check-laravel-release-refs.sh"))]
            self.assertEqual(subprocess.run(command, env=env, capture_output=True).returncode, 0)
            for result in ["forbidden", "existing"]:
                self.assertNotEqual(subprocess.run(command, env={**env, "REF_RESULT": result}, capture_output=True).returncode, 0)
            self.assertNotEqual(subprocess.run(command, env={**env, "DISTRIBUTION_REPOSITORY": "posthog/POSTHOG-PHP"}, capture_output=True).returncode, 0)
