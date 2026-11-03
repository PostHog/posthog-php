"""Test the credential-free GraphQL payload, not the live publishing endpoint."""

import base64
import importlib.util
import json
import os
import subprocess
import sys
import textwrap

from test_laravel_release import LaravelReleaseFixture, REPO, SCRIPT, git, release, snapshot

sys.path.insert(0, str(SCRIPT.parent))
spec = importlib.util.spec_from_file_location("commit_release", SCRIPT.with_name("commit_release.py"))
commits = importlib.util.module_from_spec(spec)
spec.loader.exec_module(commits)


class CommitLaravelReleaseTest(LaravelReleaseFixture):
    def test_signed_commit_payload_has_atomic_expected_head_and_scoped_changes(self):
        out = self.prepare()
        expected = git(self.root, "rev-parse", "HEAD").decode().strip()
        git(self.root, "apply", "--index", str(out / "release.patch"))
        payload = commits.request(self.root, "PostHog/posthog-php", "source", "Release Laravel")
        request = payload["variables"]["input"]
        self.assertEqual(request["expectedHeadOid"], expected)
        self.assertEqual(request["branch"], {"repositoryNameWithOwner": "PostHog/posthog-php", "branchName": "main"})
        self.assertIn("createCommitOnBranch", payload["query"])
        additions = {item["path"]: base64.b64decode(item["contents"]) for item in request["fileChanges"]["additions"]}
        self.assertEqual(json.loads(additions["packages/laravel/package.json"])["version"], "0.1.1")
        self.assertEqual(request["fileChanges"]["deletions"], [{"path": ".changeset/release.md"}])
        self.assertIn(".changeset/ledger.yaml", additions)
        self.assertIn("packages/laravel/CHANGELOG.md", additions)
        self.assertFalse(any(name in additions for name in ["composer.json", "package.json", "lib/PostHog.php"]))
        self.write(".github/workflows/release.yml", "unexpected workflow modification")
        with self.assertRaisesRegex(ValueError, "Unexpected source release change"):
            commits.request(self.root, "PostHog/posthog-php", "source", "Release Laravel")

    def test_one_source_payload_contains_both_versions_and_the_updated_pin(self):
        self.write(".changeset/core.md", '---\n"posthog-php": patch\n---\n\nCore change\n')
        snapshot(self.root)
        git(self.root, "apply", "--index", str(self.prepare() / "release.patch"))
        payload = commits.request(self.root, "PostHog/posthog-php", "source", "Update package versions")
        changes = payload["variables"]["input"]["fileChanges"]
        additions = {item["path"]: base64.b64decode(item["contents"]) for item in changes["additions"]}
        self.assertEqual(json.loads(additions["package.json"])["version"], "4.20.1")
        laravel = json.loads(additions["packages/laravel/composer.json"])
        self.assertEqual(laravel["version"], "0.1.1")
        self.assertEqual(laravel["require"]["posthog/posthog-php"], "4.20.1")
        self.assertIn("composer.lock", additions)
        self.assertIn("CHANGELOG.md", additions)
        self.assertIn("packages/laravel/CHANGELOG.md", additions)

    def distribution(self):
        candidate = self.prepare()
        destination = self.directory / "laravel-distribution"
        destination.mkdir()
        git(destination, "init", "-q", "-b", "main")
        git(destination, "remote", "add", "origin", "https://github.com/PostHog/posthog-laravel")
        release.install(candidate, destination, "PostHog/posthog-laravel")
        snapshot(destination)
        release.install(candidate, destination, "PostHog/posthog-laravel")
        return candidate, destination

    def retry_distribution(self, candidate, verified="true"):
        gh = self.bin / "gh"
        gh.write_text('''#!/usr/bin/env python3
import json, os, sys
with open(os.environ['GH_TEST_LOG'], 'a') as log:
    log.write(json.dumps(sys.argv[1:]) + '\\n')
if sys.argv[1] == 'api' and '/commits/' in sys.argv[2]:
    print(os.environ['COMMIT_VERIFIED'])
else:
    sys.exit('Unexpected API mutation on distribution retry')
''')
        gh.chmod(0o755)
        workflow = (REPO / ".github/workflows/release.yml").read_text()
        step = workflow.split('      - name: Atomic GitHub-signed distribution commit\n', 1)[1].split('      - name: Publish distribution version\n', 1)[0]
        script = textwrap.dedent(step.split('        run: |\n', 1)[1])
        output = self.directory / "outputs"
        log = self.directory / "gh.log"
        result = subprocess.run(['bash', '-c', script], cwd=REPO, env={
            **os.environ, 'PATH': f'{self.bin}:{os.environ["PATH"]}',
            'GITHUB_WORKSPACE': str(self.directory), 'RUNNER_TEMP': str(self.directory),
            'GITHUB_OUTPUT': str(output), 'GH_TEST_LOG': str(log), 'COMMIT_VERIFIED': verified,
            'REPOSITORY': 'PostHog/posthog-laravel', 'VERSION': '0.1.1',
            'SOURCE': release.read_json(candidate / 'candidate.json')['source'],
        }, capture_output=True, text=True)
        return result, output, log

    def test_distribution_retry_reuses_the_matching_verified_commit(self):
        candidate, destination = self.distribution()
        expected = git(destination, "rev-parse", "HEAD").decode().strip()
        result, output, log = self.retry_distribution(candidate)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(output.read_text(), f'commit-hash={expected}\n')
        self.assertEqual(git(destination, 'rev-parse', 'HEAD').decode().strip(), expected)
        requests = [json.loads(line) for line in log.read_text().splitlines()]
        self.assertEqual(requests, [['api', f'repos/PostHog/posthog-laravel/commits/{expected}', '--jq', '.commit.verification.verified']])

    def test_distribution_retry_rejects_an_unverified_matching_commit(self):
        candidate, _ = self.distribution()
        result, output, _ = self.retry_distribution(candidate, verified="false")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("verified signature", result.stderr)
        self.assertEqual(output.read_text(), "")

    def test_distribution_retry_rejects_a_different_committed_tree(self):
        candidate, destination = self.distribution()
        (destination / "README.md").write_text("Different package content\n")
        snapshot(destination)
        result, output, log = self.retry_distribution(candidate)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("does not match the approved candidate", result.stderr)
        self.assertEqual(output.read_text(), "")
        self.assertFalse(log.exists())

    def test_distribution_retry_detects_candidate_files_hidden_by_git_ignores(self):
        candidate, destination = self.distribution()
        (destination / "README.md").unlink()
        snapshot(destination)
        (destination / ".git/info/exclude").write_text("README.md\n")
        release.install(candidate, destination, "PostHog/posthog-laravel")
        self.assertEqual(git(destination, "status", "--porcelain"), b"")
        result, output, log = self.retry_distribution(candidate)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("does not match the approved candidate", result.stderr)
        self.assertEqual(output.read_text(), "")
        self.assertFalse(log.exists())

    def test_unchanged_source_commit_is_not_reused(self):
        with self.assertRaisesRegex(ValueError, "No release changes"):
            commits.request(self.root, "PostHog/posthog-php", "source", "Update versions")

    def test_distribution_payload_removes_obsolete_files_but_cannot_add_secrets(self):
        # A standalone distribution repository may need to remove old development files.
        self.write("old-secret.txt", "old development file")
        snapshot(self.root)
        (self.root / "old-secret.txt").unlink()
        payload = commits.request(self.root, "PostHog/posthog-laravel", "distribution", "Release")
        self.assertEqual(payload["variables"]["input"]["fileChanges"]["deletions"], [{"path": "old-secret.txt"}])
        self.write("auth.json", "new secret")
        with self.assertRaisesRegex(ValueError, "Unexpected distribution release change"):
            commits.request(self.root, "PostHog/posthog-laravel", "distribution", "Release")
