# PostHog PHP

[![PHP Version](https://img.shields.io/packagist/php-v/posthog/posthog-php?logo=php)](https://packagist.org/packages/posthog/posthog-php)
[![CI](https://github.com/PostHog/posthog-php/actions/workflows/php.yml/badge.svg)](https://github.com/PostHog/posthog-php/actions/workflows/php.yml)

Please see the main [PostHog docs](https://posthog.com/docs).

Specifically, the [PHP integration](https://posthog.com/docs/integrations/php-integration) details.

## Features

- ✅ Event capture and user identification
- ✅ Feature flag local evaluation
- ✅ **Feature flag dependencies** (new!) - Create conditional flags based on other flags
- ✅ Multivariate flags and payloads
- ✅ Group analytics
- ✅ Comprehensive test coverage

## Quick Start

1. Copy `.env.example` to `.env` and add your PostHog credentials
2. Run `php example.php` to see interactive examples of all features

## Questions?

### [Join our Slack community.](https://join.slack.com/t/posthogusers/shared_invite/enQtOTY0MzU5NjAwMDY3LTc2MWQ0OTZlNjhkODk3ZDI3NDVjMDE1YjgxY2I4ZjI4MzJhZmVmNjJkN2NmMGJmMzc2N2U3Yjc3ZjI5NGFlZDQ)

## Contributing

1. [Download PHP](https://www.php.net/manual/en/install.php) and [Composer](https://getcomposer.org/download/)
2. `php composer.phar update` to install dependencies
3. `bin/test` to run tests (this script calls `./vendor/bin/phpunit --verbose test`)

## Releasing

Releases are semi-automated via GitHub Actions. When a PR with the `release` and a version bump label is merged to `master`, the release workflow is triggered.

You'll need an approval from a PostHog engineer. If you're an employee, you can see the request in the [#approvals-client-libraries](https://app.slack.com/client/TSS5W8YQZ/C0A3UEVDDNF) channel.

### Release Process

1. **Create your PR** with the changes you want to release
2. **Add the `release` label** to the PR
3. **Add a version bump label** that should be either `patch`, `minor` or `major`
4. **Merge the PR** to `master`

Once merged, the following happens automatically:

1. A Slack notification is sent to the client libraries channel requesting approval
2. A maintainer approves the release in the GitHub `Release` environment
3. The version is bumped in `lib/PostHog.php` and `composer.json` based on the version label (`patch`, `minor`, or `major`)
4. The `CHANGELOG.md` is updated with a link to the full changelog
5. Changes are committed and pushed to `master`
6. A git tag is created (e.g., `v1.8.0`)
7. A GitHub release is created with the changelog content
8. Slack is notified of the successful release

Releases are installed directly from GitHub.
