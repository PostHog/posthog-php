#!/usr/bin/env bash
set -euo pipefail

# Composer overrides and generated locks live only in this disposable source copy.
LARAVEL=${1:?Usage: scripts/test-laravel.sh <12|13>}
case "$LARAVEL" in
  12) TESTBENCH=10 ;;
  13) TESTBENCH=11 ;;
  *) echo 'Supported Laravel versions: 12, 13' >&2; exit 1 ;;
esac
ROOT=$(git rev-parse --show-toplevel)
TEMP=$(mktemp -d)
trap 'rm -rf "$TEMP"' EXIT
SOURCE=$(git -C "$ROOT" rev-parse HEAD)
git clone --quiet --no-hardlinks --no-local "$ROOT" "$TEMP"
git -C "$TEMP" checkout --quiet --detach "$SOURCE"
if [ -n "${2:-}" ]; then
  git -C "$TEMP" apply --index "$2"
fi

# Lint only tracked source, before Composer can populate dependency directories.
while IFS= read -r -d '' file; do
  case "$file" in
    *.php) php -l "$TEMP/$file" ;;
  esac
done < <(git -C "$ROOT" ls-files -z -- packages/laravel examples/laravel)

# Test the exact source pair without putting path repositories in distribution metadata.
python3 - "$TEMP" "$LARAVEL" <<'PY'
import json
from pathlib import Path
import sys
root = Path(sys.argv[1])
core_version = json.loads((root / 'package.json').read_text())['version']
laravel_version = json.loads((root / 'packages/laravel/package.json').read_text())['version']
for relative in ['packages/laravel', 'examples/laravel']:
    path = root / relative / 'composer.json'
    if not path.exists():
        continue
    data = json.loads(path.read_text())
    repositories = [{
        'type': 'path', 'url': str(root),
        'options': {'symlink': False, 'versions': {'posthog/posthog-php': core_version}},
    }]
    if relative.startswith('examples/'):
        data['require']['posthog/posthog-laravel'] = laravel_version
        repositories.append({
            'type': 'path', 'url': str(root / 'packages/laravel'),
            'options': {'symlink': False, 'versions': {'posthog/posthog-laravel': laravel_version}},
        })
    data['repositories'] = repositories
    if relative.startswith('examples/') and sys.argv[2] == '13':
        data.setdefault('config', {}).setdefault('platform', {})['php'] = '8.3.0'
    path.write_text(json.dumps(data, indent=2) + '\n')
    path.with_name('composer.lock').unlink(missing_ok=True)
PY

cd "$TEMP/packages/laravel"
composer validate --no-check-version --no-check-publish
composer require --dev --no-update --no-interaction "orchestra/testbench:^$TESTBENCH.0" "laravel/framework:^$LARAVEL.0"
composer update --prefer-dist --no-progress --no-interaction --no-scripts
./vendor/bin/phpunit --configuration phpunit.xml

if [ -f "$TEMP/examples/laravel/composer.json" ]; then
  cd "$TEMP/examples/laravel"
  composer require --no-update --no-interaction "laravel/framework:^$LARAVEL.0"
  composer update --prefer-dist --no-progress --no-interaction --no-scripts
  # The example owns its executable test command; do not silently skip a missing command.
  composer run test
else
  echo 'Laravel example not yet present; package tests completed.'
fi
