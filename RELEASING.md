# Releasing

The PHP SDK and Laravel integration use [pnpm's native release management](https://pnpm.io/versioning), shared `.changeset/` intents, and one `.github/workflows/release.yml`. Like Ruby/Rails, they have independent versions but release according to their dependency direction.

## Record a change

```bash
pnpm change
```

Select the affected packages, bump types (`patch`, `minor`, or `major`), and a release summary. Commit the generated `.changeset/*.md` with the change.

- **Core PHP change:** select `posthog-php`. Laravel declares `"posthog-php": "workspace:*"` in its release metadata, so pnpm automatically includes a Laravel patch release to update its exact core SDK pin.
- **Laravel-only change:** select `posthog-laravel`. Core is neither bumped nor republished.
- **Both packages changed:** select both with the appropriate bump types. pnpm combines explicit bumps and dependency propagation in one plan; Laravel is not bumped twice.

An automatically generated Laravel patch is appropriate for a backwards-compatible core update. An incompatible core change also needs the corresponding Laravel code changes and an explicit appropriate bump.

Both release-metadata packages are private, so pnpm applies pending bumps without consulting npm. Laravel starts at **0.0.0**, and its initial **minor** intent produces **0.1.0**. Preparation rejects an initial 0.0.x release, including a dependency-only patch from a core change; add an explicit Laravel minor or major intent first. There is no custom initial-release bump or separate intent format.

## Dependency and version policy

Laravel's Composer manifest pins `posthog/posthog-php` to the **exact core version** recorded in the same release commit, not a caret range. For example, a release built against PHP `4.20.1` requires exactly `4.20.1`.

Laravel metadata validation uses Composer's `--no-check-all` to suppress its advisory against exact dependency constraints. Schema/publishing validation remains strict; the release validator separately enforces that the core dependency is the exact planned version.

Release preparation runs **one unfiltered `pnpm version -r`** for the whole workspace. It then synchronizes:

- Core `package.json`, `composer.json`, `lib/PostHog.php::VERSION`, `composer.lock`, and `CHANGELOG.md` when core changes.
- Laravel `package.json`, `composer.json`, `src/PostHog.php::VERSION`, and `CHANGELOG.md`, including its exact Composer core dependency.
- Shared `.changeset/ledger.yaml` and consumed intent files.

Do not version the packages separately with filters: pnpm can propagate a core bump into its dependent even with a filter, and separate plans can double-bump Laravel. One signed source commit contains the complete plan; publishing is package-specific and ordered core first.

## Automated release flow

Merging `.changeset/*.md` changes into `main` starts **Release**. Manual dispatch on `main` uses the same flow and requires pending intents.

1. A read-only job checks the complete dependency-aware plan. A stale trigger skips only if current `main` has no pending releases; otherwise it fails and asks for a new dispatch on current `main`.
2. Read-only preparation clones the exact committed source, applies the single pnpm plan, verifies the core bump-script hash before executing it, and refreshes the core Composer lock only when core changes. It produces a shared version patch, package-specific notes, a deterministic Laravel archive, and a digest manifest. Local modifications, vendors, and secrets never enter the candidate.
3. A separate read-only job independently reproduces all candidate bytes, checks planned source/distribution refs are unused, and validates both versions, the exact dependency pin, and Composer metadata. Distribution dependency resolution uses the exact candidate core via a temporary path repository because a new core version is not published yet. The archive itself never contains that override. Test suites run in `php.yml` and `laravel.yml`, not in the release workflow.
4. Slack approval notification precedes **one protected Release publishing job**. Its single environment approval covers the complete PHP-then-Laravel sequence. After approval, publishing rechecks source freshness, refs, and artifact digests, then creates **one atomic GitHub-signed source version commit** with `expectedHeadOid`. The existing PHP releaser GitHub App performs this commit. Publishing tokens are not passed to Composer steps, and package scripts, plugins, and git hooks do not run with those tokens.
5. Core gets a GitHub release only if its version changed. Existing numeric PHP tags remain unchanged, for example `4.20.2`. Laravel-only releases do not republish core.
6. Laravel publication follows in the **same job**, without another approval. It cannot run after a failed PHP publication step. Before publishing, it waits up to approximately ten minutes for the exact core version on Packagist and installs the unmodified distribution without development dependencies, scripts, plugins, or path overrides. These Composer steps receive no publishing tokens; Laravel's write tokens are minted only after dependency and source-signature checks succeed. If indexing or dependency resolution fails, Laravel is not published.
7. Laravel's dedicated distribution repository receives an atomic GitHub-signed package-only commit and **`X.Y.Z`** tag and release name, without a `v` prefix. Existing immutable `v0.1.x` mirror tags/releases remain unchanged. Source gets **`posthog-laravel-vX.Y.Z`** as both its tag and release name against the same shared version commit used by the core release, with archive and manifest attachments. The already-published `laravel-v0.1.0` release and its immutable tag/assets remain unchanged. Its source release is not marked latest, preserving the core latest-release pointer.
8. Slack success/failure notifications run after publication and include package names and versions. Failure messages distinguish completed PHP publication, incomplete Laravel publication, and a published mirror whose source release is incomplete. An unrelated advance of `main` fails freshness checks rather than publishing stale source.

## Composer distribution archives

The root `.gitattributes` excludes `packages/laravel/` from the core Composer distribution. Tools needing the full source must use a Git checkout, not a whole-repository `git archive`.

Packagist reads `composer.json` at a repository root, so Laravel publishes through a separate public **distribution repository**, recommended `PostHog/posthog-laravel`. Source remains in `packages/laravel`.

The distribution contains package `composer.json`, README, license, changelog, autocapture/Pennant guides, PHP source/config, and generated `posthog-source.json`. Tests, vendors, hidden files, locks, local configuration, and secrets are excluded. Obsolete files in the dedicated distribution repository are removed.

`packages/laravel/LICENSE.md` links to `../../LICENSE.md` in Git. Preparation embeds the regular root license contents in the archive. Other symlinks, alternate license targets, and a symlinked root license fail preparation.

## Laravel package: independent releases

The packages do not share version numbers: a core patch can produce PHP `4.20.2` and Laravel `0.1.1`, with Laravel pinned to PHP `4.20.2`. A later Laravel-only patch can produce Laravel `0.1.2` while retaining that exact PHP pin.

A change intent for Laravel alone can be created non-interactively:

```bash
pnpm change posthog-laravel --bump patch --summary "Describe the Laravel change"
```

The initial release is intentionally deferred until administrator setup is ready. To release 0.1.0 from the 0.0.0 seed, add a minor intent then:

```bash
pnpm change posthog-laravel --bump minor --summary "Initial Laravel package"
```

### Administrator prerequisites

These actions are **not** performed by release tooling or tests. Configure them before enabling publication: core changes now also plan a Laravel release.

1. Create a dedicated public distribution repository in the source organization and initialize its `main` with a signed bootstrap commit. Set `LARAVEL_DISTRIBUTION_REPOSITORY` to its `owner/repository`; it must differ from the source repository.
2. Protect **Release** with maintainer approval, no self-review or administrator bypass, and main-only deployment rules. The single publishing job uses this environment for both packages. Keep normal PR/branch protections. Naming an environment in YAML does not configure these rules.
3. Keep `GH_APP_POSTHOG_PHP_RELEASER_APP_ID` and `GH_APP_POSTHOG_PHP_RELEASER_PRIVATE_KEY` in **Release**. The existing `releaser-posthog-php` GitHub App needs Contents write access, including GitHub's `createCommitOnBranch` API and verified GitHub-signed commits.
4. Add the distribution repository to the existing App installation's selected repositories. Allow the App to publish through applicable branch/tag rules: distribution `main` and numeric release tags (`[0-9]*`), preserving protection for legacy `v*` tags, and source `posthog-laravel-v*` tags. Retain protection for existing `laravel-v*` tags. Preserve required signatures and force-push/deletion protections. The workflow creates separate Contents-write installation tokens restricted to one repository each. The source version/PHP token is explicitly revoked before dependency setup; automatic job-end cleanup remains a fallback if an earlier step fails. Laravel publishing tokens are revoked when the publishing job finishes. All tokens also expire after one hour. Read-only checks use `github.token`. No PATs, new secrets, or separate **Laravel Release** environment are needed, and publishing tokens are never passed to preparation, verification, or Composer steps.
5. Register `posthog/posthog-laravel` on Packagist using the distribution repository and configure its normal GitHub update integration. Check ownership and signed-commit permissions. No repository, Packagist registration, webhook, credential, or approval rule is created by this workflow.
6. Land the separately documented server-side `posthog-laravel` library registration before shipping the integration.

Missing configuration fails closed. Distribution preparation/verification must be able to read the public repository without publishing credentials.

## Preview and test

From a committed checkout, choose a candidate output path that does not already exist:

```bash
python3 scripts/release.py prepare \
  --repository PostHog/posthog-php --output /tmp/sdk-candidate \
  --bump-script-sha256 "$(shasum -a 256 scripts/bump-version.sh | awk '{print $1}')"
```

This only prepares local artifacts; it does not publish. The workflow pins the reviewed bump-script hash independently rather than deriving trust from the checked-out script.

```bash
bash scripts/test-laravel.sh 12
bash scripts/test-laravel.sh 13
# Test a prepared version patch in the disposable source copy:
bash scripts/test-laravel.sh 12 /tmp/sdk-candidate/release.patch
actionlint .github/workflows/laravel.yml .github/workflows/release.yml
```

## Troubleshooting and recovery

### No pending change intents

Ensure `.changeset/*.md` contains at least one `patch`, `minor`, or `major` intent that pnpm has not already consumed. Do not restore a consumed intent merely to retry publishing: it can generate a second version bump.

### Updating the core bump script

`scripts/bump-version.sh` is hash-pinned in `release.yml`. If it changes, recompute its SHA256 and update `EXPECTED_BUMP_SCRIPT_SHA256` in the same reviewed PR. Preparation rejects a mismatch before executing the script.

### Failed or cancelled release

The independent [release failure notification workflow](.github/workflows/release-failure-notification.yml) checks every completed main-branch release attempt and alerts if a job failed, timed out, or was cancelled. It also alerts when the run failed but the publishing job never ran, which happens when GitHub Actions stops mid-run and the in-run **Release Failed** job cannot run.

The in-run **Release Failed** job still posts the detailed per-package status. The independent workflow does not repeat that alert when its Slack reply was delivered, or when the reply confirms an explicit rejection. It still alerts if that reply failed, was skipped, or never ran. If the packages are published, alerts describe follow-up problems, such as a skipped released notification, rather than claiming the release failed.

Cancelled runs with no jobs are ignored, including superseded queued runs and manual cancellations before any job is created.

Job inspection retries transient GitHub API failures up to three times. If inspection still fails, the workflow logs the HTTP status or error type and posts a fallback alert with the overall run status and logs link. Error diagnostics stay in the run log, not in the Slack message.

Alerts reply to the approval thread in the release approvals Slack channel. The release workflow preserves the thread timestamp in a run artifact for 30 days, independently of release approval. Failed-job reruns reuse the latest available thread from the same or an earlier attempt. If the timestamp is missing, invalid, or unavailable, the alert falls back to a new channel message. The workflow must be present on the default branch to run; local tests do not send Slack messages.

### Partial publication

One source version commit and publishing to two repositories cannot be transactional. A failure can leave committed versions without tags, core published but Laravel unpublished, or a distribution tag without its source release.

The combined publishing job does not automatically resume after a partial publication. Rerunning it after the source version commit will fail its original-source freshness check. For an inspected manual continuation, distribution committing can reuse an unchanged distribution commit when both Laravel release refs are still unused. Reuse requires the complete committed file tree to match the approved archive byte-for-byte and GitHub to verify the commit signature; it never creates a replacement commit merely because the previous signature lookup or release request failed. An already-created tag/release still requires inspecting the existing publication rather than blindly retrying.

Inspect the retained candidate artifacts, signatures, checksums, shared source commit, and existing tags/releases before recovery. Complete only the missing publication at the **same recorded version**, using the original artifacts and commit. In particular, wait for Packagist indexing rather than creating another release intent. An all-workflow rerun after versions were consumed does not create a new candidate automatically.

For a missing core GitHub release, after verifying the source commit and notes:

```bash
VERSION=<version>
COMMIT_SHA=<verified-shared-version-commit>
gh release create "$VERSION" --target "$COMMIT_SHA" --title "$VERSION" --notes-file /path/to/candidate/core-notes.md
```

Never move an already published tag, overwrite distribution history, or rewrite source commits. Correct bad published versions with signed follow-up commits and a new intent/version. If an incorrect version commit must be undone, use a new signed `git revert`, not a force-push. Do not delete an already distributed version to make a retry look successful.
