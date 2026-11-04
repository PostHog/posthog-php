"""Release boundaries, tested entirely in disposable repositories (no credentials)."""

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "release.py"
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
        self.write("composer.json", json.dumps({"name": "posthog/posthog-php", "version": "4.20.0"}, indent=2) + "\n")
        self.write("composer.lock", '{"fixture_version":"4.20.0"}\n')
        self.write("CHANGELOG.md", "# posthog-php\n\n## 4.20.0\n\nPrevious release\n")
        self.write("scripts/bump-version.sh", (REPO / "scripts/bump-version.sh").read_text())
        self.write("lib/PostHog.php", "<?php class PostHog { public const VERSION = '4.20.0'; }\n")
        self.write("packages/laravel/package.json", '{"name":"posthog-laravel","version":"0.1.0","private":true,"dependencies":{"posthog-php":"workspace:*"}}\n')
        self.write("packages/laravel/composer.json", '{"name":"posthog/posthog-laravel","version":"0.1.0","require":{"posthog/posthog-php":"4.20.0"}}\n')
        self.write("packages/laravel/src/PostHog.php", "<?php class PostHog { public const VERSION = '0.1.0'; }\n")
        self.write("packages/laravel/config/posthog.php", "<?php return [];\n")
        self.write("packages/laravel/README.md", "# PostHog Laravel\n")
        self.write("packages/laravel/LICENSE.md", "MIT\n")
        self.intent("patch")
        snapshot(self.root)
        self.bin = self.directory / "bin"
        self.bin.mkdir()
        composer = self.bin / "composer"
        composer.write_text('''#!/usr/bin/env python3
import json, pathlib, sys
assert sys.argv[1:] == ['update', '--lock', '--no-install', '--no-interaction', '--no-audit', '--no-scripts', '--no-plugins']
version = json.loads(pathlib.Path('composer.json').read_text())['version']
pathlib.Path('composer.lock').write_text(json.dumps({'fixture_version': version}) + '\\n')
''')
        composer.chmod(0o755)

    def write(self, name, data):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(data)

    def intent(self, bump, name="release"):
        self.write(f".changeset/{name}.md", f'---\n"posthog-laravel": {bump}\n---\n\nLaravel change\n')

    def prepare(self, name="candidate"):
        out = self.directory / name
        with patch.dict(os.environ, {"PATH": f"{self.bin}:{os.environ['PATH']}"}):
            release.prepare(self.root, out, "PostHog/posthog-php", hashlib.sha256((REPO / "scripts/bump-version.sh").read_bytes()).hexdigest())
        return out


class LaravelReleaseTest(LaravelReleaseFixture):
    def test_version_sync_and_laravel_only_patch(self):
        before = {name: (self.root / name).read_bytes() for name in ['package.json', 'composer.json', 'composer.lock', 'lib/PostHog.php', 'CHANGELOG.md']}
        status = git(self.root, "status", "--porcelain")
        out = self.prepare()
        self.assertEqual(status, git(self.root, "status", "--porcelain"))
        git(self.root, "apply", str(out / "release.patch"))
        package = self.root / release.PACKAGE
        self.assertEqual(json.loads((package / "package.json").read_text())["version"], "0.1.1")
        self.assertEqual(json.loads((package / "composer.json").read_text())["version"], "0.1.1")
        self.assertIn("VERSION = '0.1.1'", (package / "src/PostHog.php").read_text())
        self.assertEqual(before, {name: (self.root / name).read_bytes() for name in before})
        self.assertFalse(release.read_json(out / "candidate.json")["core_changed"])
        self.assertEqual(release.read_json(package / "composer.json")["require"]["posthog/posthog-php"], "4.20.0")
        self.assertFalse((self.root / ".changeset/release.md").exists())
        self.assertIn("posthog-laravel@0.1.1:", (self.root / ".changeset/ledger.yaml").read_text())
        self.assertIn("Laravel change", (package / "CHANGELOG.md").read_text())
        self.assertEqual(json.loads((self.root / "package.json").read_text())["version"], "4.20.0")
        self.assertEqual(json.loads((self.root / "composer.json").read_text())["version"], "4.20.0")
        self.assertIn("4.20.0", (self.root / "lib/PostHog.php").read_text())
        self.assertEqual(git(self.root, "diff", "--cached", "--name-only"), b"")

    def test_core_only_change_releases_laravel_with_an_updated_exact_pin(self):
        (self.root / ".changeset/release.md").unlink()
        self.write(".changeset/core.md", '---\n"posthog-php": patch\n---\n\nCore only\n')
        snapshot(self.root)
        out = self.prepare()
        git(self.root, "apply", str(out / "release.patch"))
        self.assertEqual(release.read_json(self.root / "package.json")["version"], "4.20.1")
        self.assertEqual(release.read_json(self.root / "composer.json")["version"], "4.20.1")
        self.assertIn("VERSION = '4.20.1'", (self.root / "lib/PostHog.php").read_text())
        laravel = release.read_json(self.root / release.PACKAGE / "composer.json")
        self.assertEqual(laravel["version"], "0.1.1")
        self.assertEqual(laravel["require"]["posthog/posthog-php"], "4.20.1")
        self.assertFalse((self.root / ".changeset/core.md").exists())
        self.assertIn("Core only", (out / "core-notes.md").read_text())
        with tarfile.open(out / "package.tar.gz") as archive:
            self.assertEqual(json.load(archive.extractfile("composer.json"))["require"]["posthog/posthog-php"], "4.20.1")

    def test_joint_changes_use_one_plan_without_double_bumping_the_dependent(self):
        self.write(".changeset/core.md", '---\n"posthog-php": minor\n---\n\nCore feature\n')
        self.intent("minor")
        snapshot(self.root)
        out = self.prepare()
        git(self.root, "apply", str(out / "release.patch"))
        self.assertEqual(release.read_json(self.root / "package.json")["version"], "4.21.0")
        laravel = release.read_json(self.root / release.PACKAGE / "composer.json")
        self.assertEqual(laravel["version"], "0.2.0")
        self.assertEqual(laravel["require"]["posthog/posthog-php"], "4.21.0")
        self.assertEqual(list((self.root / ".changeset").glob("*.md")), [])
        snapshot(self.root)
        with self.assertRaisesRegex(ValueError, "No pending"):
            self.prepare("second")

    def test_native_versioning_applies_the_largest_pending_bump(self):
        self.intent("minor", "second")
        snapshot(self.root)
        self.assertEqual(release.read_json(self.prepare() / "candidate.json")["version"], "0.2.0")

    def test_shared_intent_is_consumed_once_by_both_packages(self):
        self.write(".changeset/mixed.md", '---\n"posthog-php": patch\n"posthog-laravel": minor\n---\n\nShared change\n')
        snapshot(self.root)
        out = self.prepare()
        git(self.root, "apply", str(out / "release.patch"))
        self.assertFalse((self.root / ".changeset/mixed.md").exists())
        self.assertFalse((self.root / ".changeset/release.md").exists())
        ledger = (self.root / ".changeset/ledger.yaml").read_text()
        self.assertIn("posthog-php@4.20.1:", ledger)
        self.assertIn("posthog-laravel@0.2.0:", ledger)
        snapshot(self.root)
        with self.assertRaisesRegex(ValueError, "No pending change intents"):
            self.prepare("second")

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
            self.assertEqual(set(archive.getnames()), {"composer.json", "README.md", "LICENSE.md", "CHANGELOG.md", "AUTOCAPTURE.md", "PENNANT.md", "src/PostHog.php", "config/posthog.php", "posthog-source.json"})
            self.assertFalse(any(b"secret" in archive.extractfile(item).read() for item in archive))
            composer = json.load(archive.extractfile("composer.json"))
            self.assertEqual(composer["require"]["posthog/posthog-php"], "4.20.0")
            metadata = json.load(archive.extractfile("posthog-source.json"))
            self.assertEqual(metadata["commit"], git(self.root, "rev-parse", "HEAD").decode().strip())
            self.assertEqual(metadata["tag"], "posthog-laravel-v0.1.1")

    def test_root_license_link_is_embedded_as_a_regular_distribution_file(self):
        self.write("LICENSE.md", "Root MIT license\n")
        license_path = self.root / release.PACKAGE / "LICENSE.md"
        license_path.unlink()
        license_path.symlink_to("../../LICENSE.md")
        snapshot(self.root)
        with tarfile.open(self.prepare() / "package.tar.gz") as archive:
            self.assertTrue(archive.getmember("LICENSE.md").isfile())
            self.assertEqual(archive.extractfile("LICENSE.md").read(), b"Root MIT license\n")
        self.assertTrue(license_path.is_symlink())

    def test_license_link_rejects_other_targets_and_a_linked_root_license(self):
        self.write("LICENSE.md", "MIT\n")
        self.write("README.md", "Not a license\n")
        license_path = self.root / release.PACKAGE / "LICENSE.md"
        license_path.unlink()
        license_path.symlink_to("../../README.md")
        snapshot(self.root)
        with self.assertRaisesRegex(ValueError, "regular distribution file"):
            self.prepare()
        license_path.unlink()
        license_path.symlink_to("../../LICENSE.md")
        (self.root / "LICENSE.md").unlink()
        (self.root / "LICENSE.md").symlink_to("README.md")
        snapshot(self.root)
        with self.assertRaisesRegex(ValueError, "regular distribution file"):
            self.prepare()

    def test_failed_subprocess_diagnostics_are_visible(self):
        self.write("failing-tool.py", "import sys\nprint('preparation output')\nprint('dependency conflict details', file=sys.stderr)\nsys.exit(37)\n")
        result = subprocess.run([
            sys.executable, "-c",
            "import release, sys; release.run(sys.executable, sys.argv[1], cwd=sys.argv[2])",
            str(self.root / "failing-tool.py"), str(self.root),
        ], env={**os.environ, "PYTHONPATH": str(SCRIPT.parent)}, capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("preparation output", result.stdout)
        self.assertIn("dependency conflict details", result.stderr)
        self.assertIn("exit status 37", result.stderr)

    def test_initial_patch_releases_are_rejected_including_core_dependency_bumps(self):
        for name in ["package.json", "composer.json"]:
            metadata = release.read_json(self.root / release.PACKAGE / name)
            metadata["version"] = "0.0.0"
            self.write(str(release.PACKAGE / name), json.dumps(metadata))
        self.write("packages/laravel/src/PostHog.php", "<?php class PostHog { public const VERSION = '0.0.0'; }\n")
        for kind in ["laravel", "core"]:
            with self.subTest(kind=kind):
                (self.root / ".changeset/release.md").unlink(missing_ok=True)
                if kind == "laravel":
                    self.intent("patch")
                else:
                    self.write(".changeset/core.md", '---\n"posthog-php": patch\n---\n\nCore only\n')
                snapshot(self.root)
                with self.assertRaisesRegex(ValueError, "Initial Laravel release requires a minor or major intent"):
                    self.prepare("candidate-" + kind)

    def test_initial_minor_release_starts_at_zero(self):
        for name in ["package.json", "composer.json"]:
            metadata = release.read_json(self.root / release.PACKAGE / name)
            metadata["version"] = "0.0.0"
            self.write(str(release.PACKAGE / name), json.dumps(metadata))
        self.write("packages/laravel/src/PostHog.php", "<?php class PostHog { public const VERSION = '0.0.0'; }\n")
        self.intent("minor")
        snapshot(self.root)
        out = self.prepare()
        self.assertEqual(release.read_json(out / "candidate.json")["version"], "0.1.0")
        git(self.root, "apply", str(out / "release.patch"))
        self.assertEqual(release.read_json(self.root / release.PACKAGE / "composer.json")["version"], "0.1.0")
        self.assertIn("VERSION = '0.1.0'", (self.root / release.PACKAGE / "src/PostHog.php").read_text())

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

    def test_modified_core_bump_script_is_rejected_before_execution(self):
        self.write(".changeset/core.md", '---\n"posthog-php": patch\n---\n\nCore change\n')
        self.write("scripts/bump-version.sh", "exit 73\n")
        snapshot(self.root)
        with self.assertRaisesRegex(ValueError, "bump script sha256 mismatch"):
            self.prepare()

    def test_candidate_validation_checks_both_versions_and_the_exact_pin(self):
        out = self.prepare()
        git(self.root, "apply", str(out / "release.patch"))
        release.validate(self.root, out)
        composer = release.read_json(self.root / release.PACKAGE / "composer.json")
        composer["require"]["posthog/posthog-php"] = "^4.20"
        self.write("packages/laravel/composer.json", json.dumps(composer))
        with self.assertRaisesRegex(ValueError, "pin mismatch"):
            release.validate(self.root, out)


if __name__ == "__main__":
    unittest.main()
