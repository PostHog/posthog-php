"""Test the credential-free GraphQL payload, not the live publishing endpoint."""

import base64
import importlib.util
import json
import sys

from test_laravel_release import LaravelReleaseFixture, SCRIPT, git, snapshot

sys.path.insert(0, str(SCRIPT.parent))
spec = importlib.util.spec_from_file_location("commit_laravel_release", SCRIPT.with_name("commit_laravel_release.py"))
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
        self.assertEqual(request["fileChanges"]["deletions"], [{"path": ".laravel-changes/release.json"}])
        self.assertFalse(any(name in additions for name in ["composer.json", "package.json", "lib/PostHog.php"]))
        self.write("composer.json", "unexpected core modification")
        with self.assertRaisesRegex(ValueError, "Unexpected source release change"):
            commits.request(self.root, "PostHog/posthog-php", "source", "Release Laravel")

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
