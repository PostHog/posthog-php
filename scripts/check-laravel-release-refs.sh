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

# The distribution must already be a public, dedicated repository with a main branch.
[ "$(gh api "repos/$DISTRIBUTION_REPOSITORY" --jq '.private')" = false ]
gh api "repos/$DISTRIBUTION_REPOSITORY/git/ref/heads/main" --jq '.object.sha' > /dev/null
for endpoint in releases/tags git/ref/tags; do
  missing "repos/$SOURCE_REPOSITORY/$endpoint/laravel-v$VERSION"
  missing "repos/$DISTRIBUTION_REPOSITORY/$endpoint/v$VERSION"
done
