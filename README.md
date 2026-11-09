# PostHog PHP

[![PHP Version](https://img.shields.io/packagist/php-v/posthog/posthog-php?logo=php)](https://packagist.org/packages/posthog/posthog-php)
[![CI](https://github.com/PostHog/posthog-php/actions/workflows/php.yml/badge.svg)](https://github.com/PostHog/posthog-php/actions/workflows/php.yml)

Please see the main [PostHog docs](https://posthog.com/docs).

SDK usage examples and code snippets live in the official documentation so they stay up to date.

## Documentation

- [PHP library docs](https://posthog.com/docs/libraries/php)
- [Laravel integration docs](https://posthog.com/docs/libraries/laravel)
- [Upgrade notes](UPGRADING.md), including GeoIP configuration and compatibility

## OpenTelemetry context

The optional `PostHog\OpenTelemetry\ContextSpanProcessor` adds session and person IDs to application spans inside this client's request context. Register it on the application's existing OpenTelemetry provider. OpenTelemetry remains an optional application dependency.

A client with no project key keeps request context but adds no span attributes. A valid-key client with the `noop` consumer still adds them.

The registered processor also enables request span enrichment when `withContext()` starts. This lookup skips Fibers because uninitialized OpenTelemetry Fiber storage can return another execution path's span. Child spans still use the active Fiber's PostHog context. New Fibers and queue jobs need explicit context propagation.

Existing span attributes take precedence. The processor preserves the application's provider, sampling, and exporters. See the [PHP tracing guide](https://posthog.com/docs/distributed-tracing/installation/php) and [Laravel guide](https://posthog.com/docs/libraries/laravel) for setup.

## Transactional email

`emails()` sends one email to one recipient, from an email template made transactional in PostHog or from content your code rendered, and reads what happened to it.

The client needs a project secret API key (`phs_...`) as `secretKey`. Create it with the "Send transactional email" preset (`transactional_email:write`) in your project settings, and add `transactional_email:read` if you read statuses with a key that can't send. Keys made with `hog_flow:write` still work. A client whose `host` is PostHog Cloud sends to its region's app host. For any other `host`, such as a reverse proxy, set the `app_host` option to your PostHog app host: email requests never go to a custom `host`. Loading flag definitions still sends the secret key to `host`, so when your proxy must not see the key, build the client with `loadFeatureFlags: false` and don't call `loadFlags()`:

```php
$client = new \PostHog\Client('phc_...', [
    'host' => 'ph.example.com', // your proxy
    'app_host' => 'https://eu.posthog.com',
], loadFeatureFlags: false, secretKey: 'phs_...');
PostHog::init(client: $client);
```

```php
PostHog::init('phc_...', ['host' => 'https://eu.i.posthog.com'], secretKey: 'phs_...');

// A template made in PostHog, by its ID
$result = PostHog::emails()->send([
    'to' => 'user@example.com',
    'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b',
    'template_data' => ['name' => 'Ada'], // fills {{ variables.name }}
    'distinct_id' => 'user_123',
    'idempotency_key' => 'welcome:user_123',
]);

// Content your code rendered, named by a key
PostHog::emails()->send([
    'to' => 'user@example.com',
    'template_key' => 'password-reset',
    'subject' => 'Reset your password',
    'html' => $html,
    'distinct_id' => 'user_123',
]);

// Later, such as in a job: for a few seconds after the send, get() throws not_found
$status = PostHog::emails()->get($result->getId()); // queued, sent, delivered, bounced, ...
```

- **Two forms.** Pass `template_id` with optional `template_data`, or `template_key` with `subject` and `html`, `text` or both. The content form also takes `from` (a verified sender), `reply_to` and `capture_engagement` (default `true`). PostHog derives a missing `text` from the `html`. To send an email built with a template library such as React Email, render it to HTML in your app and pass `html`. The SDK adds no rendering dependency.
- **Marketing email.** Leave `category` out, or pass `Emails::CATEGORY_TRANSACTIONAL`, to send transactional email. Any other value is the key of one of your project's marketing message categories, such as `'category' => 'product-updates'`. A marketing send honors the recipient's opt-outs and needs `{{ unsubscribe_url }}` in the body. A template send follows the template's own category, so leave `category` out or pass the template's. PostHog refuses a send with `unknown_category`, `category_mismatch`, `unsubscribe_link_required` or `recipient_opted_out`.
- **Pass `distinct_id`.** Without it, the email's engagement events go to a person whose distinct ID is the email address.
- **A result means queued**, not delivered. `send()` returns a `SendEmailResult` with the message ID. `get()` reads the status for 30 days after the send. For a few seconds after the send, it throws `not_found`.
- **Errors.** A send that is not queued throws `PostHog\EmailError`. Branch on `getErrorCode()`, such as `recipient_suppressed` or `missing_app_host`, or on `getErrorName()`, such as `validation_error`. An invalid argument throws before any request, with an `invalid_*` code.
- **Retries.** Network errors, rate limits and an unavailable service are retried up to `email_request_max_retries` times (default 2) with the same idempotency key, so a retry never sends twice. Each request times out after `email_request_timeout_ms` (default 15000). When a job runner already retries the send, pass `'max_retries' => 0` and an `idempotency_key` derived from what the email is for, such as `welcome:user_123`. A generated key does not survive a job retry.
- **Moving from another provider.** Set the `migrated_from` option, such as `'resend'`, so PostHog knows where your sends came from.

## Questions?

### [Join our Slack community.](https://join.slack.com/t/posthogusers/shared_invite/enQtOTY0MzU5NjAwMDY3LTc2MWQ0OTZlNjhkODk3ZDI3NDVjMDE1YjgxY2I4ZjI4MzJhZmVmNjJkN2NmMGJmMzc2N2U3Yjc3ZjI5NGFlZDQ)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for local setup and test instructions.

## Releasing

See [RELEASING.md](RELEASING.md).
