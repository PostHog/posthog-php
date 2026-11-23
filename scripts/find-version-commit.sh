#!/usr/bin/env bash
# Prints the shared version commit that an approved release created on main: the only
# first-parent child of the approved source, whose tree is exactly that source plus the
# approved version patch, with a GitHub-verified signature from a GitHub App.
set -euo pipefail

: "${REPOSITORY:?Repository is required}"
: "${SOURCE:?Approved source commit is required}"
: "${PATCH:?Approved version patch is required}"
[[ "$REPOSITORY" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]
[[ "$SOURCE" =~ ^[0-9a-f]{40}$ ]]

commit=$(git rev-list --first-parent --parents HEAD | awk -v source="$SOURCE" '$2 == source && NF == 2 {print $1}')
if ! [[ "$commit" =~ ^[0-9a-f]{40}$ ]]; then
  echo "No single shared version commit follows $SOURCE on main" >&2
  exit 1
fi

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
GIT_INDEX_FILE="$work/index" git read-tree "$SOURCE"
GIT_INDEX_FILE="$work/index" git apply --cached "$PATCH"
if [ "$(GIT_INDEX_FILE="$work/index" git write-tree)" != "$(git rev-parse "$commit^{tree}")" ]; then
  echo "Shared version commit $commit is not exactly the approved version patch" >&2
  exit 1
fi

read -r verified author_type author <<< "$(gh api "repos/$REPOSITORY/commits/$commit" --jq '"\(.commit.verification.verified) \(.author.type) \(.author.login)"')"
if [ "$verified" != true ] || [ "$author_type" != Bot ] || [[ "$author" != *"[bot]" ]]; then
  echo "Shared version commit $commit must be GitHub-verified and authored by a GitHub App (got $verified $author_type $author)" >&2
  exit 1
fi
echo "$commit"
