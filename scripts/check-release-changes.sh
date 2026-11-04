#!/usr/bin/env bash
set -euo pipefail

MAIN_SHA=$(git rev-parse --verify origin/main)
if [ "$MAIN_SHA" != "$TRIGGER_SHA" ]; then
  git checkout --quiet --detach "$MAIN_SHA"
fi

versions() {
  python3 - <<'PY'
import json
for path in ['package.json', 'packages/laravel/package.json']:
    print(json.load(open(path))['version'])
PY
}

BEFORE=$(versions)
pnpm version -r
AFTER=$(versions)

if [ "$BEFORE" = "$AFTER" ]; then
  echo 'has-changesets=false' >> "$GITHUB_OUTPUT"
  echo 'No pending change intents'
elif [ "$MAIN_SHA" != "$TRIGGER_SHA" ]; then
  echo "main moved from $TRIGGER_SHA to $MAIN_SHA with pending changes; dispatch Release on main" >&2
  exit 1
else
  echo 'has-changesets=true' >> "$GITHUB_OUTPUT"
  echo "Pending release versions: $AFTER"
fi
