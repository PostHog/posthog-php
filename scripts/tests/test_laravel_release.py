"""Release boundaries, tested entirely in disposable repositories (no credentials)."""

import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "laravel_release.py"
REPO = SCRIPT.parents[1]
spec = importlib.util.spec_from_file_location("laravel_release", SCRIPT)
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


def git(root, *args, input=None):
    return subprocess.check_output(["git", *args], cwd=root, input=input, stderr=subprocess.PIPE)


def snapshot(root):
    """Create a synthetic fixture commit object, not a user commit or signing operation."""
    git(root, "add", ".")
    tree = git(root, "write-tree").decode().strip()
    parent = git(root, "rev-parse", "--verify", "HEAD").decode().strip() if (root / ".git/refs/heads/main").exists() else None
    data = f"tree {tree}\n" + (f"parent {parent}\n" if parent else "")
    data += "author Fixture <fixture@example.invalid> 1700000000 +0000\ncommitter Fixture <fixture@example.invalid> 1700000000 +0000\n\nFixture\n"
    sha = git(root, "hash-object", "-t", "commit", "-w", "--stdin", input=data.encode()).decode().strip()
    git(root, "update-ref", "refs/heads/main", sha)


class LaravelReleaseFixture(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.root = self.directory / "source"
        self.root.mkdir()
        git(self.root, "init", "-q", "-b", "main")
        self.write("package.json", json.dumps({"name": "posthog-php", "version": "4.20.0", "private": True, "packageManager": "pnpm@11.13.1"}))
        self.write("pnpm-workspace.yaml", (REPO / "pnpm-workspace.yaml").read_text())
        self.write("composer.json", '{"name":"posthog/posthog-php","version":"4.20.0"}\n')
        self.write("lib/PostHog.php", "<?php class PostHog { public const VERSION = '4.20.0'; }\n")
        self.write("packages/laravel/package.json", '{"name":"posthog-laravel","version":"0.1.0","private":true}\n')
        self.write("packages/laravel/composer.json", '{"name":"posthog/posthog-laravel","version":"0.1.0","require":{"posthog/posthog-php":"^4.20"}}\n')
        self.write("packages/laravel/src/PostHog.php", "<?php class PostHog { public const VERSION = '0.1.0'; }\n")
        self.write("packages/laravel/config/posthog.php", "<?php return [];\n")
        self.write("packages/laravel/README.md", "# PostHog Laravel\n")
        self.write("packages/laravel/LICENSE.md", "MIT\n")
        self.intent("patch")
        snapshot(self.root)

    def write(self, name, data):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(data)

    def intent(self, bump, name="release"):
        self.write(f".laravel-changes/{name}.json", json.dumps({"package": "posthog-laravel", "bump": bump, "summary": "Laravel change"}))

    def prepare(self, name="candidate"):
        out = self.directory / name
        release.prepare(self.root, out, "PostHog/posthog-php")
        return out


class LaravelReleaseTest(LaravelReleaseFixture):
    def test_version_sync_and_laravel_only_patch(self):
        self.write(".changeset/core.md", '---\n"posthog-php": minor\n---\n\nCore change\n')
        snapshot(self.root)
        status = git(self.root, "status", "--porcelain")
        out = self.prepare()
        self.assertEqual(status, git(self.root, "status", "--porcelain"))
        git(self.root, "apply", str(out / "release.patch"))
        package = self.root / release.PACKAGE
        self.assertEqual(json.loads((package / "package.json").read_text())["version"], "0.1.1")
        self.assertEqual(json.loads((package / "composer.json").read_text())["version"], "0.1.1")
        self.assertIn("VERSION = '0.1.1'", (package / "src/PostHog.php").read_text())
        self.assertTrue((self.root / ".changeset/core.md").exists())
        self.assertFalse((self.root / ".laravel-changes/release.json").exists())
        self.assertEqual(json.loads((self.root / "package.json").read_text())["version"], "4.20.0")
        self.assertEqual(json.loads((self.root / "composer.json").read_text())["version"], "4.20.0")
        self.assertIn("4.20.0", (self.root / "lib/PostHog.php").read_text())
        self.assertEqual(git(self.root, "diff", "--cached", "--name-only"), b"")

    def test_core_only_intent_cannot_prepare_laravel_release(self):
        (self.root / ".laravel-changes/release.json").unlink()
        self.write(".changeset/core.md", '---\n"posthog-php": patch\n---\n\nCore only\n')
        snapshot(self.root)
        with self.assertRaisesRegex(ValueError, "No pending Laravel change intents"):
            self.prepare()

    def test_initial_release_and_largest_bump(self):
        self.intent("initial")
        snapshot(self.root)
        self.assertEqual(release.read_json(self.prepare() / "candidate.json")["version"], "0.1.0")
        self.intent("patch")
        self.intent("minor", "second")
        snapshot(self.root)
        self.assertEqual(release.read_json(self.prepare("second") / "candidate.json")["version"], "0.2.0")
        self.assertEqual(release.next_version("1.2.3", ["patch", "major"]), "2.0.0")
        with self.assertRaises(ValueError):
            release.next_version("0.1.0", ["initial", "patch"])

    def test_archive_is_deterministic_and_excludes_development_files(self):
        self.write("packages/laravel/AUTOCAPTURE.md", "# Automatic collection\n")
        self.write("packages/laravel/PENNANT.md", "# Pennant integration\n")
        for name in ["vendor/autoload.php", "tests/Test.php", ".env", ".env.testing", "composer.lock", "phpunit.xml", "auth.json", "src/.env.php", "src/vendor/private.php", "src/secrets/private.php", "config/key.pem", "config/.secret.php"]:
            self.write("packages/laravel/" + name, "secret-development-value")
        snapshot(self.root)
        self.write("packages/laravel/src/Untracked.php", "local secret")
        self.write("packages/laravel/src/PostHog.php", "local modification")
        first, second = self.prepare(), self.prepare("second")
        for name in ["package.tar.gz", "release.patch", "candidate.json", "notes.md"]:
            self.assertEqual((first / name).read_bytes(), (second / name).read_bytes())
        with tarfile.open(first / "package.tar.gz") as archive:
            self.assertEqual(set(archive.getnames()), {"composer.json", "README.md", "LICENSE.md", "AUTOCAPTURE.md", "PENNANT.md", "src/PostHog.php", "config/posthog.php", "posthog-source.json"})
            self.assertFalse(any(b"secret" in archive.extractfile(item).read() for item in archive))
            composer = json.load(archive.extractfile("composer.json"))
            self.assertEqual(composer["require"]["posthog/posthog-php"], "^4.20")
            metadata = json.load(archive.extractfile("posthog-source.json"))
            self.assertEqual(metadata["commit"], git(self.root, "rev-parse", "HEAD").decode().strip())
            self.assertEqual(metadata["tag"], "laravel-v0.1.1")

    def test_mismatch_repository_override_and_symlink_fail_closed(self):
        self.write("packages/laravel/src/PostHog.php", "<?php class PostHog { public const VERSION = '9.0.0'; }\n")
        snapshot(self.root)
        with self.assertRaisesRegex(ValueError, "VERSION mismatch"):
            self.prepare()
        self.write("packages/laravel/src/PostHog.php", "<?php class PostHog { public const VERSION = '0.1.0'; }\n")
        composer = release.read_json(self.root / release.PACKAGE / "composer.json")
        composer["repositories"] = [{"type": "path", "url": "../.."}]
        self.write("packages/laravel/composer.json", json.dumps(composer))
        snapshot(self.root)
        with self.assertRaisesRegex(ValueError, "repository overrides"):
            self.prepare()
        del composer["repositories"]
        self.write("packages/laravel/composer.json", json.dumps(composer))
        (self.root / "packages/laravel/src/Link.php").symlink_to("../../../../secret.php")
        snapshot(self.root)
        with self.assertRaisesRegex(ValueError, "regular distribution file"):
            self.prepare()

    def test_install_replaces_only_configured_distribution_checkout(self):
        out = self.prepare()
        destination = self.directory / "distribution"
        destination.mkdir()
        git(destination, "init", "-q")
        git(destination, "remote", "add", "origin", "https://github.com/PostHog/posthog-laravel")
        (destination / "old.txt").write_text("old")
        with self.assertRaisesRegex(ValueError, "separate from source"):
            release.install(out, destination, "PostHog/posthog-php")
        release.install(out, destination, "PostHog/posthog-laravel")
        self.assertFalse((destination / "old.txt").exists())
        self.assertTrue((destination / "composer.json").exists())
        self.assertFalse((destination / "packages").exists())
        self.assertTrue((destination / ".git").is_dir())
        (out / "package.tar.gz").write_bytes(b"corrupted")
        with self.assertRaisesRegex(ValueError, "digest mismatch"):
            release.install(out, destination, "PostHog/posthog-laravel")

    def test_archive_rejects_traversal_before_deleting_distribution(self):
        out = self.prepare()
        payload = release.archive({"../secret": b"bad"})
        (out / "package.tar.gz").write_bytes(payload)
        manifest = release.read_json(out / "candidate.json")
        manifest["archive_sha256"] = hashlib.sha256(payload).hexdigest()
        release.write_json(out / "candidate.json", manifest)
        destination = self.directory / "distribution"
        destination.mkdir()
        (destination / ".git").mkdir()
        (destination / "keep").touch()
        with self.assertRaisesRegex(ValueError, "Unexpected archive member"):
            release.install(out, destination, "PostHog/posthog-laravel")
        self.assertTrue((destination / "keep").exists())

    @unittest.skipUnless(shutil.which("pnpm"), "pnpm 11.13.1 is required")
    def test_native_core_release_does_not_consume_laravel_intents(self):
        self.write(".changeset/core.md", '---\n"posthog-php": patch\n---\n\nCore change\n')
        snapshot(self.root)
        before = {p: p.read_bytes() for p in (self.root / release.PACKAGE).rglob("*") if p.is_file()}
        intent = (self.root / ".laravel-changes/release.json").read_bytes()
        output = subprocess.check_output(["pnpm", "version", "-r", "--no-git-checks"], cwd=self.root, stderr=subprocess.STDOUT)
        self.assertIn(b"4.20.1", output)
        self.assertEqual(json.loads((self.root / "package.json").read_text())["version"], "4.20.1")
        self.assertFalse((self.root / ".changeset/core.md").exists())
        self.assertEqual((self.root / ".laravel-changes/release.json").read_bytes(), intent)
        self.assertEqual(before, {p: p.read_bytes() for p in before})

    @unittest.skipUnless(shutil.which("pnpm"), "pnpm 11.13.1 is required")
    def test_native_selective_filter_retains_mixed_intent_until_both_released(self):
        self.write("pnpm-workspace.yaml", (self.root / "pnpm-workspace.yaml").read_text().replace("  - '.'", "  - '.'\n  - 'packages/*'"))
        self.write(".changeset/mixed.md", '---\n"posthog-php": patch\n"posthog-laravel": minor\n---\n\nShared change\n')
        snapshot(self.root)
        def apply(package):
            subprocess.check_output(["pnpm", "--filter", package, "version", "-r", "--no-git-checks"], cwd=self.root, stderr=subprocess.STDOUT)
        apply("posthog-php")
        self.assertEqual(release.read_json(self.root / "package.json")["version"], "4.20.1")
        self.assertEqual(release.read_json(self.root / release.PACKAGE / "package.json")["version"], "0.1.0")
        self.assertTrue((self.root / ".changeset/mixed.md").exists())
        apply("posthog-php")
        self.assertEqual(release.read_json(self.root / "package.json")["version"], "4.20.1")
        apply("posthog-laravel")
        self.assertEqual(release.read_json(self.root / release.PACKAGE / "package.json")["version"], "0.2.0")
        self.assertFalse((self.root / ".changeset/mixed.md").exists())


if __name__ == "__main__":
    unittest.main()
