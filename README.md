# PostHog PHP

[![PHP Version](https://img.shields.io/packagist/php-v/posthog/posthog-php?logo=php)](https://packagist.org/packages/posthog/posthog-php)
[![CI](https://github.com/PostHog/posthog-php/actions/workflows/php.yml/badge.svg)](https://github.com/PostHog/posthog-php/actions/workflows/php.yml)

Please see the main [PostHog docs](https://posthog.com/docs).

Specifically, the [PHP integration](https://posthog.com/docs/integrations/php-integration) details.

## Features

- ✅ Event capture and user identification
- ✅ Error tracking with manual exception capture
- ✅ Opt-in automatic PHP exception, error, and fatal shutdown capture
- ✅ Feature flag local evaluation
- ✅ **Feature flag dependencies** (new!) - Create conditional flags based on other flags
- ✅ Multivariate flags and payloads
- ✅ Group analytics
- ✅ Comprehensive test coverage

## Quick Start

1. Copy `.env.example` to `.env` and add your PostHog credentials
2. Run `php example.php` to see interactive examples of all features

## Error Tracking

Manual exception capture:

```php
PostHog::captureException($exception, 'user-123', [
    '$current_url' => 'https://example.com/settings',
]);
```

Opt-in automatic capture from the core SDK:

```php
PostHog::init('phc_xxx', [
    'enable_error_tracking' => true,
    'capture_uncaught_exceptions' => true,
    'capture_errors' => true,
    'capture_fatal_errors' => true,
    'error_reporting_mask' => E_ALL,
    'excluded_exceptions' => [
        \InvalidArgumentException::class,
    ],
    'error_tracking_include_source_context' => true,
    'error_tracking_context_lines' => 5,
    'error_tracking_max_frames' => 50,
    'error_tracking_context_provider' => static function (array $payload): array {
        return [
            'distinctId' => $_SESSION['user_id'] ?? null,
            'properties' => [
                '$current_url' => $_SERVER['REQUEST_URI'] ?? null,
            ],
        ];
    },
]);
```

Auto error tracking is off by default. When enabled, the SDK chains existing exception and error handlers instead of replacing app behavior.

## Questions?

### [Join our Slack community.](https://join.slack.com/t/posthogusers/shared_invite/enQtOTY0MzU5NjAwMDY3LTc2MWQ0OTZlNjhkODk3ZDI3NDVjMDE1YjgxY2I4ZjI4MzJhZmVmNjJkN2NmMGJmMzc2N2U3Yjc3ZjI5NGFlZDQ)

## Contributing

1. [Download PHP](https://www.php.net/manual/en/install.php) and [Composer](https://getcomposer.org/download/)
2. `php composer.phar update` to install dependencies
3. `bin/test` to run tests (this script calls `./vendor/bin/phpunit --verbose test`)

## Releasing

See [RELEASING.md](RELEASING.md).
