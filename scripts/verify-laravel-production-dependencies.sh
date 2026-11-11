#!/usr/bin/env bash
# Waits for the exact core version on Packagist, then installs the unmodified distribution
# archive without development dependencies, scripts or plugins. Never given publishing tokens.
set -euo pipefail

: "${CORE_VERSION:?Core version is required}"
mkdir "$RUNNER_TEMP/production-check"
tar -xzf "$RUNNER_TEMP/candidate/package.tar.gz" -C "$RUNNER_TEMP/production-check"
for attempt in {1..30}; do
  if curl --fail --silent --show-error --connect-timeout 10 --max-time 30 https://repo.packagist.org/p2/posthog/posthog-php.json -o "$RUNNER_TEMP/core.json" &&
    python3 -c 'import json,sys; sys.exit(not any(v["version"].lstrip("v") == sys.argv[1] for v in json.load(open(sys.argv[2]))["packages"]["posthog/posthog-php"]))' "$CORE_VERSION" "$RUNNER_TEMP/core.json"; then
    composer install --working-dir="$RUNNER_TEMP/production-check" --no-dev --prefer-dist --no-progress --no-interaction --no-scripts --no-plugins
    exit 0
  fi
  if [ "$attempt" -eq 30 ]; then
    echo "Core $CORE_VERSION is not available on Packagist; Laravel was not published" >&2
    exit 1
  fi
  sleep 20
done
