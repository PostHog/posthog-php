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

Both release-metadata packages are private, so pnpm applies pending bumps without consulting npm. Laravel starts at **0.0.0**, and its initial **minor** intent produces **0.1.0**. There is no custom initial-release bump or separate intent format.

## Dependency and version policy

Laravel's Composer manifest pins `posthog/posthog-php` to the **exact core version** recorded in the same release commit, not a caret range. Currently that pin is `4.20.1`.

Release preparation runs **one unfiltered `pnpm version -r`** for the whole workspace. It then synchronizes:

- Core `package.json`, `composer.json`, `lib/PostHog.php::VERSION`, `composer.lock`, and `CHANGELOG.md` when core changes.
- Laravel `package.json`, `composer.json`, `src/PostHog.php::VERSION`, and `CHANGELOG.md`, including its exact Composer core dependency.
- Shared `.changeset/ledger.yaml` and consumed intent files.

Do not version the packages separately with filters: pnpm can propagate a core bump into its dependent even with a filter, and separate plans can double-bump Laravel. One signed source commit contains the complete plan; publishing is package-specific and ordered core first.

## Automated release flow

Merging `.changeset/*.md` changes into `main` starts **Release**. Manual dispatch on `main` uses the same flow and requires pending intents.

1. A read-only job checks the complete dependency-aware plan. A stale trigger skips only if current `main` has no pending releases; otherwise it fails and asks for a new dispatch on current `main`.
2. Read-only preparation clones the exact committed source, applies the single pnpm plan, verifies the core bump-script hash before executing it, and refreshes the core Composer lock only when core changes. It produces a shared version patch, package-specific notes, a deterministic Laravel archive, and a digest manifest. Local modifications, vendors, and secrets never enter the candidate.
3. A separate read-only job independently reproduces all candidate bytes, checks planned source/distribution refs are unused, validates both versions and the exact dependency pin, and tests Laravel 12/13 against the patched candidate source. Distribution dependency resolution uses the exact candidate core via a temporary path repository because a new core version is not published yet. The archive itself never contains that override.
4. Slack approval notification precedes the protected **Release** environment. After approval, publishing rechecks source freshness, refs, and artifact digests, then creates **one atomic GitHub-signed source version commit** with `expectedHeadOid`. The existing PHP releaser GitHub App performs this commit. No Composer installs, package scripts, or git hooks run with that App token.
5. Core gets a GitHub release only if its version changed. Existing numeric PHP tags remain unchanged, for example `4.20.2`. Laravel-only releases do not republish core.
6. Laravel publication runs afterward in the protected **Laravel Release** environment. Before publishing, it waits up to approximately ten minutes for the exact core version on Packagist and installs the unmodified distribution without development dependencies, scripts, plugins, or path overrides. These Composer steps receive no publishing tokens. If indexing or dependency resolution fails, Laravel is not published.
7. Laravel's dedicated distribution repository receives an atomic GitHub-signed package-only commit and **`vX.Y.Z`** release. Source gets **`laravel-vX.Y.Z`** against the same shared version commit used by the core release, with archive and manifest attachments. Its source release is not marked latest, preserving the core latest-release pointer.
8. Slack success/failure notifications run after publication. An unrelated advance of `main` fails freshness checks rather than publishing stale source.

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

The initial intent already requests a minor bump from 0.0.0 to 0.1.0.

### Administrator prerequisites

These actions are **not** performed by release tooling or tests. Configure them before enabling publication: core changes now also plan a Laravel release.

1. Create a dedicated public distribution repository and initialize its `main` with a signed bootstrap commit. Set `LARAVEL_DISTRIBUTION_REPOSITORY` to its `owner/repository`; it must differ from the source repository.
2. Protect both **Release** and **Laravel Release** environments with maintainer approval, no self-review, and protected-main-only deployment rules. Keep normal PR/branch protections. Naming an environment in YAML does not configure these rules.
3. Keep the existing PHP releaser App credentials in **Release**. Its Contents write access must support GitHub's `createCommitOnBranch` API and verified GitHub-signed commits without disabling signature requirements.
4. Store environment-only `LARAVEL_SOURCE_TOKEN` and `LARAVEL_DISTRIBUTION_TOKEN` in **Laravel Release**, scoped narrowly to the respective repositories. Source needs release/tag access; distribution needs Contents write access including signed `createCommitOnBranch` requests and releases. Do not expose these tokens to preparation, verification, or Composer steps.
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
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s scripts/tests -v
bash scripts/test-laravel.sh 12
bash scripts/test-laravel.sh 13
# Test a prepared version patch in the disposable source copy:
bash scripts/test-laravel.sh 12 /tmp/sdk-candidate/release.patch
actionlint .github/workflows/laravel.yml .github/workflows/release.yml
```

Fixtures use pnpm 11.13.1, disposable repositories, fake Composer/gh clients, and no publishing credentials. They cover core-only automatic Laravel patching, Laravel-only core preservation, joint changes, exact pins, single-consumption ledgers, signed commit requests, deterministic archives, and secret/path/symlink exclusion.

## Troubleshooting and recovery

### No pending change intents

Ensure `.changeset/*.md` contains at least one `patch`, `minor`, or `major` intent that pnpm has not already consumed. Do not restore a consumed intent merely to retry publishing: it can generate a second version bump.

### Updating the core bump script

`scripts/bump-version.sh` is hash-pinned in `release.yml`. If it changes, recompute its SHA256 and update `EXPECTED_BUMP_SCRIPT_SHA256` in the same reviewed PR. Preparation rejects a mismatch before executing the script.

### Partial publication

One source version commit and publishing to two repositories cannot be transactional. A failure can leave committed versions without tags, core published but Laravel unpublished, or a distribution tag without its source release.

Inspect the retained candidate artifacts, signatures, checksums, shared source commit, and existing tags/releases before recovery. Complete only the missing publication at the **same recorded version**, using the original artifacts and commit. In particular, wait for Packagist indexing rather than creating another release intent. An all-workflow rerun after versions were consumed does not create a new candidate automatically.

For a missing core GitHub release, after verifying the source commit and notes:

```bash
VERSION=<version>
COMMIT_SHA=<verified-shared-version-commit>
gh release create "$VERSION" --target "$COMMIT_SHA" --title "$VERSION" --notes-file /path/to/candidate/core-notes.md
```

Never move an already published tag, overwrite distribution history, or rewrite source commits. Correct bad published versions with signed follow-up commits and a new intent/version. If an incorrect version commit must be undone, use a new signed `git revert`, not a force-push. Do not delete an already distributed version to make a retry look successful.
