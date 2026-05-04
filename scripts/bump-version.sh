#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 1 ]; then
  echo "Usage: $0 <new-version>" >&2
  exit 1
fi

NEW_VERSION="$1"

perl -0pi -e "s/public const VERSION = '[^']+'/public const VERSION = '$NEW_VERSION'/" lib/PostHog.php
perl -0pi -e "s/\"version\": \"[^\"]+\"/\"version\": \"$NEW_VERSION\"/" composer.json
