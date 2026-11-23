#!/usr/bin/env bash
set -euo pipefail

: "${SOURCE_REPOSITORY:?Source repository is required}"
: "${DISTRIBUTION_REPOSITORY:?Configure LARAVEL_DISTRIBUTION_REPOSITORY first}"
: "${VERSION:?Version is required}"
[[ "$SOURCE_REPOSITORY" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]
[[ "$DISTRIBUTION_REPOSITORY" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
[ "$(printf %s "$SOURCE_REPOSITORY" | tr '[:upper:]' '[:lower:]')" != "$(printf %s "$DISTRIBUTION_REPOSITORY" | tr '[:upper:]' '[:lower:]')" ]

# Only an explicit HTTP 404 means absent. Permissions, rate limits and outages fail closed.
missing() {
  local endpoint=$1 output
  if output=$(gh api "$endpoint" 2>&1); then
    echo "Release ref already exists: $endpoint" >&2
    exit 1
  fi
  if ! grep -q 'HTTP 404' <<< "$output"; then
    echo "Could not check release ref: $output" >&2
    exit 1
  fi
}

# Resume mode: a tag may already exist only as a lightweight tag on the expected commit,
# and a release only as a published release of that tag. Prints key-tag/key-release states.
missing_or_at() {
  local key=$1 repository=$2 tag=$3 expected=$4 output tag_state=missing release_state=missing
  if output=$(gh api "repos/$repository/git/ref/tags/$tag" --jq '"\(.object.type) \(.object.sha)"' 2>&1); then
    if [ "$output" != "commit $expected" ]; then
      echo "Release tag $repository@$tag is $output, expected commit $expected" >&2
      exit 1
    fi
    tag_state=present
  elif ! grep -q 'HTTP 404' <<< "$output"; then
    echo "Could not check release ref: $output" >&2
    exit 1
  fi
  if output=$(gh api "repos/$repository/releases/tags/$tag" --jq '.draft' 2>&1); then
    if [ "$output" != false ] || [ "$tag_state" != present ]; then
      echo "Release $repository@$tag is a draft or has no tag; inspect it manually" >&2
      exit 1
    fi
    release_state=present
  elif ! grep -q 'HTTP 404' <<< "$output"; then
    echo "Could not check release ref: $output" >&2
    exit 1
  fi
  echo "$key-tag=$tag_state"
  echo "$key-release=$release_state"
}

# The distribution must already be a public, dedicated repository with a main branch.
[ "$(gh api "repos/$DISTRIBUTION_REPOSITORY" --jq '.private')" = false ]
gh api "repos/$DISTRIBUTION_REPOSITORY/git/ref/heads/main" --jq '.object.sha' > /dev/null
if [ "${CORE_CHANGED:-false}" = true ]; then
  [[ "${CORE_VERSION:?Core version is required}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
fi

if [ -n "${RESUME_SOURCE_COMMIT:-}" ]; then
  [[ "$RESUME_SOURCE_COMMIT" =~ ^[0-9a-f]{40}$ ]]
  [[ "${RESUME_DISTRIBUTION_COMMIT:?Expected distribution commit is required}" =~ ^[0-9a-f]{40}$ ]]
  for endpoint in releases/tags git/ref/tags; do
    missing "repos/$DISTRIBUTION_REPOSITORY/$endpoint/v$VERSION"
  done
  if [ "${CORE_CHANGED:-false}" = true ]; then
    missing_or_at core "$SOURCE_REPOSITORY" "$CORE_VERSION" "$RESUME_SOURCE_COMMIT"
  fi
  missing_or_at source "$SOURCE_REPOSITORY" "posthog-laravel-v$VERSION" "$RESUME_SOURCE_COMMIT"
  missing_or_at distribution "$DISTRIBUTION_REPOSITORY" "$VERSION" "$RESUME_DISTRIBUTION_COMMIT"
  exit 0
fi

for endpoint in releases/tags git/ref/tags; do
  if [ "${CORE_CHANGED:-false}" = true ]; then
    missing "repos/$SOURCE_REPOSITORY/$endpoint/$CORE_VERSION"
  fi
  missing "repos/$SOURCE_REPOSITORY/$endpoint/posthog-laravel-v$VERSION"
  missing "repos/$DISTRIBUTION_REPOSITORY/$endpoint/$VERSION"
  missing "repos/$DISTRIBUTION_REPOSITORY/$endpoint/v$VERSION"
done
