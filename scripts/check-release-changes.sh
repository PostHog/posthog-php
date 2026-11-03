#!/usr/bin/env bash
set -euo pipefail

PACKAGE=${1:?Usage: check-release-changes.sh <posthog-php|posthog-laravel>}
case "$PACKAGE" in
  posthog-php) MANIFEST=package.json ;;
  posthog-laravel) MANIFEST=packages/laravel/package.json ;;
  *) echo "Unknown release package: $PACKAGE" >&2; exit 1 ;;
esac

MAIN_SHA=$(git rev-parse --verify origin/main)
if [ "$MAIN_SHA" != "$TRIGGER_SHA" ]; then
  git checkout --quiet --detach "$MAIN_SHA"
fi

version() {
  python3 -c 'import json, sys; print(json.load(open(sys.argv[1]))["version"])' "$MANIFEST"
}

BEFORE=$(version)
pnpm --filter "$PACKAGE" version -r
AFTER=$(version)

if [ "$BEFORE" = "$AFTER" ]; then
  echo 'has-changesets=false' >> "$GITHUB_OUTPUT"
  echo "No pending change intents for $PACKAGE"
elif [ "$MAIN_SHA" != "$TRIGGER_SHA" ]; then
  echo "main moved from $TRIGGER_SHA to $MAIN_SHA with pending changes for $PACKAGE; dispatch its release workflow on main" >&2
  exit 1
else
  echo 'has-changesets=true' >> "$GITHUB_OUTPUT"
  echo "Pending release: $PACKAGE $BEFORE -> $AFTER"
fi
