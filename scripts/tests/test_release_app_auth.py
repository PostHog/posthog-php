"""Check release authentication boundaries without reading keys or minting tokens."""

import os
from pathlib import Path
import re
import subprocess
import tempfile
import textwrap
import unittest


REPO = Path(__file__).resolve().parents[2]


class ReleaseAppAuthTest(unittest.TestCase):
    def setUp(self):
        self.workflow = (REPO / ".github/workflows/release.yml").read_text()

    def job(self, name):
        match = re.search(
            rf"(?ms)^  {re.escape(name)}:\n(.*?)(?=^  [a-zA-Z0-9_-]+:\n|\Z)", self.workflow,
        )
        self.assertIsNotNone(match, f"Missing release job: {name}")
        return match.group(1)

    def step(self, job, *, identifier=None, name=None):
        for block in re.split(r"(?m)(?=^      - )", self.job(job)):
            if identifier and re.search(rf"(?m)^        id: {re.escape(identifier)}$", block):
                return block
            if name and block.startswith(f"      - name: {name}\n"):
                return block
        self.fail(f"Missing {job} step: {identifier or name}")

    def test_publish_jobs_use_the_existing_protected_app_credentials(self):
        for job in ["release", "publish-laravel"]:
            header = self.job(job).split("    steps:", 1)[0]
            self.assertIn("    environment: Release\n", header)
            self.assertIn("      contents: read\n", header)
        self.assertNotIn("secrets.LARAVEL_SOURCE_TOKEN", self.workflow)
        self.assertNotIn("secrets.LARAVEL_DISTRIBUTION_TOKEN", self.workflow)

    def test_app_tokens_request_one_repository_and_job_end_revocation(self):
        source = "${{ github.event.repository.name }}"
        distribution = "${{ steps.distribution-repository.outputs.name }}"
        for job, identifier, repository in [
            ("release", "releaser", source),
            ("publish-laravel", "distribution-releaser", distribution),
            ("publish-laravel", "source-releaser", source),
        ]:
            with self.subTest(job=job, identifier=identifier):
                step = self.step(job, identifier=identifier)
                self.assertIn("actions/create-github-app-token@", step)
                self.assertIn("client-id: ${{ secrets.GH_APP_POSTHOG_PHP_RELEASER_APP_ID }}", step)
                self.assertIn("private-key: ${{ secrets.GH_APP_POSTHOG_PHP_RELEASER_PRIVATE_KEY }}", step)
                self.assertIn("owner: ${{ github.repository_owner }}", step)
                self.assertIn(f"repositories: {repository}\n", step)
                self.assertEqual(re.findall(r"(?m)^          permission-([a-z-]+): (\S+)$", step), [("contents", "write")])
                self.assertIn("skip-token-revoke: false", step)

    def test_laravel_write_tokens_are_minted_only_after_dependency_validation(self):
        job = self.job("publish-laravel")
        distribution = self.step("publish-laravel", identifier="distribution-releaser")
        source = self.step("publish-laravel", identifier="source-releaser")
        signed = self.step("publish-laravel", name="Require signed source commit")
        published = self.step("publish-laravel", name="Publish distribution version")
        wait = self.step("publish-laravel", name="Wait for the exact published core and test production dependency resolution")
        self.assertLess(job.index(wait), job.index(distribution))
        self.assertLess(job.index(signed), job.index(distribution))
        self.assertLess(job.index(self.step("publish-laravel", identifier="distribution-repository")), job.index(distribution))
        self.assertLess(job.index(published), job.index(source))
        self.assertNotIn("GH_TOKEN", wait)
        self.assertNotIn("secrets.", wait)
        self.assertNotIn("outputs.token", wait)

    def test_source_and_distribution_operations_use_their_own_tokens(self):
        distribution_token = "${{ steps.distribution-releaser.outputs.token }}"
        for name in ["Atomic GitHub-signed distribution commit", "Publish distribution version"]:
            self.assertIn(f"GH_TOKEN: {distribution_token}", self.step("publish-laravel", name=name))
        checkout = next(
            block for block in re.split(r"(?m)(?=^      - )", self.job("publish-laravel"))
            if "          path: laravel-distribution\n" in block
        )
        self.assertIn(f"token: {distribution_token}", checkout)
        self.assertIn("persist-credentials: false", checkout)
        self.assertIn("GH_TOKEN: ${{ steps.source-releaser.outputs.token }}", self.step("publish-laravel", name="Publish independent source release and archive"))
        for name in ["Recheck refs, main and approved candidate digests", "Require signed source commit"]:
            self.assertIn("GH_TOKEN: ${{ github.token }}", self.step("publish-laravel", name=name))

    def test_distribution_token_target_rejects_empty_foreign_and_source_repositories(self):
        resolver = self.step("publish-laravel", identifier="distribution-repository")
        script = textwrap.dedent(resolver.split("        run: |\n", 1)[1])
        for repository, accepted in [
            ("PostHog/posthog-laravel", True),
            ("posthog/custom-laravel-mirror", True),
            ("", False),
            ("PostHog/", False),
            ("PostHog", False),
            ("OtherOrg/posthog-laravel", False),
            ("PostHog/posthog-php", False),
            ("PostHog/nested/repository", False),
            ("PostHog/posthog-laravel,posthog-php", False),
            ("PostHog/mirror\nname=posthog-php", False),
        ]:
            with self.subTest(repository=repository), tempfile.TemporaryDirectory() as temporary:
                output = Path(temporary) / "outputs"
                result = subprocess.run(["bash", "-c", script], env={
                    **os.environ, "GITHUB_REPOSITORY": "PostHog/posthog-php",
                    "DISTRIBUTION_REPOSITORY": repository, "GITHUB_OUTPUT": str(output),
                }, capture_output=True, text=True)
                self.assertEqual(result.returncode == 0, accepted, result.stderr)
                if accepted:
                    self.assertEqual(output.read_text(), f"name={repository.split('/')[1]}\n")
                else:
                    self.assertFalse(output.exists() and output.read_text())
