# PostHog PHP

Please see the main [PostHog docs](https://posthog.com/docs).

Specifically, the [PHP integration](https://posthog.com/docs/integrations/php-integration) details.

## Features

- ✅ Event capture and user identification
- ✅ Feature flag local evaluation
- ✅ **Feature flag dependencies** (new!) - Create conditional flags based on other flags
- ✅ Multivariate flags and payloads
- ✅ Group analytics
- ✅ Comprehensive test coverage

## Installation

**Requirements:**
- PHP >= 8.0
- JSON extension (`ext-json`)

Install via Composer:

```bash
composer require posthog/posthog-php
```

## Usage

### Basic Setup

Initialize PostHog with your project API key:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use PostHog\PostHog;

PostHog::init(
    'YOUR_PROJECT_API_KEY',
    [
        'host' => 'https://app.posthog.com', // or your self-hosted URL
        'debug' => false,
        'ssl' => true
    ],
    null,
    'YOUR_PERSONAL_API_KEY' // Required for local feature flag evaluation
);
```

### Capturing Events

Track events with custom properties:

```php
PostHog::capture([
    'distinctId' => 'user_123',
    'event' => 'button_clicked',
    'properties' => [
        'button_name' => 'signup',
        'page' => 'homepage'
    ]
]);
```

### Identifying Users

Associate user properties with a distinct ID:

```php
PostHog::identify([
    'distinctId' => 'user_123',
    'properties' => [
        'email' => 'user@example.com',
        'plan' => 'premium',
        'signup_date' => '2024-01-15'
    ]
]);
```

### Feature Flags

Evaluate feature flags with local evaluation (no API call required):

```php
// Get a specific feature flag
$flagEnabled = PostHog::getFeatureFlag(
    'new-feature',
    'user_123',
    [],
    ['email' => 'user@example.com'], // user properties
    [],
    true // only_evaluate_locally
);

// Get all flags for a user
$allFlags = PostHog::getAllFlags('user_123', [], [], [], true);
```

### Group Analytics

Track events for groups like companies or teams:

```php
PostHog::capture([
    'distinctId' => 'user_123',
    'event' => 'feature_used',
    'properties' => [
        'feature_name' => 'advanced_analytics'
    ],
    'groups' => [
        'company' => 'acme_corp',
        'team' => 'engineering'
    ]
]);
```

### Advanced Examples

For comprehensive examples including feature flag dependencies and multivariate flags, see [`example.php`](example.php) or run:

```bash
php example.php
```

## Questions?

### [Join our Slack community.](https://join.slack.com/t/posthogusers/shared_invite/enQtOTY0MzU5NjAwMDY3LTc2MWQ0OTZlNjhkODk3ZDI3NDVjMDE1YjgxY2I4ZjI4MzJhZmVmNjJkN2NmMGJmMzc2N2U3Yjc3ZjI5NGFlZDQ)

## Contributing

1. [Download PHP](https://www.php.net/manual/en/install.php) and [Composer](https://getcomposer.org/download/)
2. `php composer.phar update` to install dependencies
3. `bin/test` to run tests (this script calls `./vendor/bin/phpunit --verbose test`)
