# Releasing

This repository uses [Changesets](https://github.com/changesets/changesets) for version management and an automated GitHub Actions workflow for releases.

## How to Release

### 1. Add a Changeset

When making a change that should be released, add a changeset:

```bash
pnpm changeset
```

This prompts you to select the version bump (`patch`, `minor`, or `major`) and write a short release summary. Commit the generated file in `.changeset/` with your pull request.

### 2. Merge the Pull Request

After review, merge the PR to `main`. No GitHub release label is required.

A push to `main` that includes `.changeset/*.md` changes automatically starts the release workflow. The workflow then:

1. Checks for pending changesets
2. Prepares a release candidate patch for the triggering commit in a read-only job without release secrets, after verifying the release bump script hash
3. Verifies the release candidate in a separate read-only job and fails if the tag or GitHub Release already exists
4. Notifies the client libraries team in Slack for approval only after candidate preparation and verification both succeed
5. Waits for one approval from a maintainer via the GitHub `Release` environment
6. Applies the verified release candidate patch, creates a signed release commit, tags the release, and creates a GitHub Release
7. Notifies Slack and records PostHog failure events from separate follow-up jobs, outside the approved publishing job

### Manual Trigger

You can also manually trigger the release workflow from the Actions tab with `workflow_dispatch`. Manual runs still require pending changesets.

## Version Bumping

Changesets determines the next version from the committed changeset files:

- **patch**: bug fixes, documentation updates, and internal changes
- **minor**: backwards-compatible features
- **major**: breaking changes

## Troubleshooting

### No changesets found

If the release workflow reports that no changesets were found, make sure your PR includes at least one releasable `.changeset/*.md` file.

### Updating the release bump script

The release workflow validates `scripts/bump-version.sh` with a hardcoded SHA256 before executing it. If you modify `scripts/bump-version.sh`, recompute its hash and update `expected_bump_script_sha256` in `.github/workflows/release.yml` in the same PR:

```bash
sha256sum scripts/bump-version.sh
```

### Manual recovery after a failed release

Most failures happen before anything is published. If the workflow fails before the `Commit version bump` step, no commit, tag, or GitHub Release should exist.

If the signed release commit was created but `Create GitHub release` failed, prefer completing the release manually instead of rolling back:

```bash
VERSION=<version>
COMMIT_SHA=<signed-release-commit-sha>

CHANGELOG_ENTRY=$(awk -v defText="see CHANGELOG.md" '/^## /{if (flag) exit; flag=1} flag && /^##$/{exit} flag; END{if (!flag) print defText}' CHANGELOG.md)
gh release create "$VERSION" --target "$COMMIT_SHA" --title "$VERSION" --notes "$CHANGELOG_ENTRY"
```

If the wrong GitHub Release or tag was created, delete both before retrying:

```bash
VERSION=<version>

gh release delete "$VERSION" --yes --cleanup-tag || true
git push origin ":refs/tags/$VERSION" || true
```

If the signed version bump commit itself is wrong and must be undone, revert it with a new commit rather than force-pushing `main`:

```bash
git switch main
git pull --ff-only
RELEASE_COMMIT=<signed-release-commit-sha>
git revert "$RELEASE_COMMIT"
git push origin main
```

After rollback, add or restore the needed changeset and let the release workflow prepare a new release candidate.
