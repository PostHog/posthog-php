# Contributing

Thanks for your interest in improving the PostHog PHP SDK.

## Commit signing

This repo requires all commits to be signed. To configure commit signing, see the [PostHog handbook](https://posthog.com/handbook/engineering/security#commit-signing). Set `commit.gpgsign` to `true` in your git config so that every commit is signed, not only the ones you sign by hand.

## Development setup

1. Install [PHP](https://www.php.net/manual/en/install.php) and [Composer](https://getcomposer.org/download/). Enable PHP's `zlib` extension for the gzip regression tests. This is a development-only dependency, not an SDK runtime requirement.
2. Install dependencies using the same command CI uses:

   ```bash
   composer install --prefer-dist --no-progress
   ```

## Running the example app

1. Copy `.env.example` to `.env` and add your PostHog credentials.
2. Run the interactive example script:

   ```bash
   php example.php
   ```

## CI-aligned checks

Run the test command used in CI:

```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --bootstrap vendor/autoload.php --configuration phpunit.xml --coverage-text
```

The PHPUnit transport tests use a loopback HTTP server, not live PostHog endpoints. They require the cURL extension, the `curl` and `gzip` commands, and permission to start PHP subprocesses and listen on loopback ports.

Run the adapter fidelity and report checker tests (Python standard library only):

```bash
python3 -m unittest discover -s sdk_compliance_adapter -v
```

After the Composer install above, run PHP_CodeSniffer with the Composer-installed tool and `phpcs.xml`, matching CI:

```bash
./vendor/bin/phpcs --standard=phpcs.xml --extensions=php lib test
```

## Laravel development

`packages/laravel` is an independent Composer package, not a pnpm workspace project.
Its CI covers Laravel 12 on PHP 8.2–8.5 and Laravel 13 on PHP 8.3–8.5.
From a committed checkout, run:

```bash
bash scripts/test-laravel.sh 12
bash scripts/test-laravel.sh 13
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s scripts/tests -v
```

The framework checks lint tracked package/example PHP files, then install and test in
an automatically cleaned temporary source copy. They inject local Composer path
repositories for the checked-out core and Laravel package there, never into your
checkout or distribution metadata. The example's `composer test` runs when its
`composer.json` is available; a missing test command then fails rather than skips.
The release fixtures use pnpm 11.13.1 and fake publishing tools; they never publish
or use credentials. Root PHPCS checks remain scoped to `lib` and `test`.

Record Laravel release changes with
`python3 scripts/laravel_release.py intent patch "Summary"`, **not** `pnpm change`.
See [the Laravel release guide](RELEASING.md#laravel-package-independent-releases)
for the first-release intent and required administrator setup.

## Public API changes

Public API is hard to change once it ships, so agree on it before writing the implementation. Our [SDK guidelines](https://posthog.com/handbook/engineering/sdks/guidelines) explain how we design it.

This section is for external contributors. PostHog maintainers (members of the PostHog GitHub org) agree on API shape in the PR itself, so they don't need a separate issue.

- **Before you start:** if you need something the SDK doesn't support and it would add or change a public option, method, or type, open an issue describing your use case. Wait for a maintainer to agree on the API shape there before you implement it. Context is more useful to us than code at this stage.
- **Already specified?** If a published [sdk-spec](https://github.com/PostHog/sdk-specs) defines the API, that's the agreement, so you don't need an issue.
- **Already have a PR open?** Don't stop or rewrite it. Call out the public API change at the top of the PR description, and link or open an issue so we can discuss the shape there.
- Check first whether an existing option or hook, such as `before_send`, already covers the use case. We avoid offering two ways to do the same thing.
- If a reviewer suggests a different API on your PR, confirm it with them before re-implementing. Treat it as a question, not an instruction.

`composer api:update` regenerates `api/public-api.json`, and CI runs `composer api:check` to catch an outdated snapshot. A diff in that file means your change touches public API.

## Pull requests

Please follow the existing project conventions and include tests when you change behavior.
