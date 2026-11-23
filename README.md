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

A client with no project token keeps request context but adds no span attributes. A client with a valid token and with the `noop` consumer still adds them.

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

// With copies and a file: content is the file's bytes, and the SDK uploads them before the send
PostHog::emails()->send([
    'to' => 'user@example.com',
    'template_key' => 'receipt',
    'subject' => 'Your receipt',
    'html' => $html,
    'bcc' => 'finance@example.com',
    'attachments' => [['filename' => 'receipt.pdf', 'content' => file_get_contents($receiptPath)]],
]);

// Later, such as in a job: for a few seconds after the send, get() throws not_found
$status = PostHog::emails()->get($result->getId()); // queued, sent, delivered, bounced, ...
```

- **Two forms.** Pass `template_id` with optional `template_data`, or `template_key` with `subject` and `html`, `text` or both. The content form also takes `from` (a verified sender), `reply_to`, `cc`, `bcc`, `attachments`, `sensitive`, `tracking` and `category` (marketing categories cannot be combined with `cc`, `bcc` or `attachments`). PostHog derives a missing `text` from the `html`. To send an email built with a template library such as React Email, render it to HTML in your app and pass `html`. The SDK adds no rendering dependency.
- **Copies and files.** `cc` and `bcc` take one address or a list of up to 10. `attachments` takes up to 10 files, each with `filename`, `content` (the file's bytes) and optional `content_type` and `content_id`. The whole email can be at most 40 MB after Base64 encoding, which leaves about 29 MB for the files. The 40 MB counts the subject and the bodies, and without `text` it counts the `html` twice, since PostHog sends the text it derives from it. The SDK checks both limits before any request (`too_many_attachments`, `message_too_large`). Pass the bytes, not Base64: when you hold Base64, such as from a Resend payload, pass `base64_decode($content)`. Show a file inline with `<img src="cid:logo">` and `'content_id' => 'logo'`. A PostHog version that doesn't take these fields yet refuses the send with an error naming the field, and never drops it.
- **How files travel.** The SDK hashes each file with SHA-256, asks PostHog which files it still needs, uploads those straight to PostHog's file storage with the URL and headers PostHog answers (never with your secret key), then sends the email with each file's hash. A file the project already uploaded isn't uploaded again. The storage host differs from your app host, so allow it if your servers restrict outbound traffic. Files go only to an `https` storage URL, unless your `app_host` is plain `http://` for local development. A storage 429, 500, 502, 503 or 504 is retried like the send. A failed upload throws `attachment_upload_failed` with the storage's answer, and no email is sent. An upload never follows a redirect: storage that answers one fails that file the same way, without naming the URL.
- **Sensitive emails.** Pass `'sensitive' => true` when the email carries login links, reset tokens or codes. A sensitive email keeps its links unchanged unless `tracking` turns clicks on, leaves its subject off engagement events, and keeps its content out of logs. Left out, the email takes the setting of the template made in PostHog whose key matches `template_key`, and is sensitive when no template matches.
- **Tracking.** Left out, PostHog counts the email's opens and captures its engagement events, and tracks clicks only when the email isn't sensitive. `'tracking' => true` turns on opens, clicks and engagement events. `'tracking' => false` turns them all off: no open pixel, no rewritten links, no engagement events. An array such as `['clicks' => true]` sets `opens` (default `true`), `clicks` (default: on unless the email is sensitive) and `engagement` (default `true`) one by one. Turn clicks on for a sensitive email only when its links are safe to rewrite. Click tracking needs open tracking. `capture_engagement` is deprecated in favor of `tracking` with `engagement`. It still works, but a send can't set both.
- **Message categories.** A send with `template_key` takes a `category`. Leave it out to send transactional email, or, when code-send categories are enabled for the project, to follow the category of the template that `template_key` names. `Emails::CATEGORY_TRANSACTIONAL` sends transactional email with no category, so PostHog refuses it with `category_mismatch` on a template in a category. Likewise, passing a category object when `template_key` matches an uncategorized saved template returns `category_mismatch`: use a different `template_key` for a marketing send (assigning a saved template to a marketing category returns `marketing_template`), or, for a transactional send, use a different `template_key` or assign the saved template to that transactional category in PostHog first. To file the email under a category, pass the category itself, such as `'category' => ['key' => 'newsletter', 'type' => 'marketing', 'name' => 'Newsletter', 'description' => 'Monthly product news']`. The `type` is `'marketing'` or `'transactional'`, and `description` is optional. When code-send categories are not yet enabled for the project, PostHog refuses an explicit category array with `categories_not_enabled`. When `template_key` doesn't match a saved template, PostHog creates the category the first time a send names its key, or restores it if it was deleted, and otherwise leaves it unchanged. When `template_key` matches a saved template, the send uses that template's own category without restoring an archived category. Its `name` and `description` apply only when the send creates the category; change them later in PostHog. A marketing send honors the recipient's opt-outs, adds unsubscribe headers, and needs a clickable `<a href="{{ unsubscribe_url }}">` link in `html` (or `{{ unsubscribe_url }}` in `text` when there is no `html`). A marketing code send takes no `cc` or `bcc` (`copies_not_allowed`) and cannot carry `attachments` (`workflow_can_wait` on a verified sender because marketing sending limits can hold delivery, or `sandbox_fields_unsupported` when using the PostHog test sender; the SDK uploads attachments PostHog does not already have before PostHog refuses the send, skipping uploads for files PostHog reports as `present`). A template made in PostHog must be enabled for transactional sends (`template_not_transactional` otherwise); when an otherwise sendable template is in a marketing category, PostHog refuses a send whose `template_key` names it with `marketing_template`. PostHog refuses a send with `category_mismatch`, `too_many_categories`, `unsubscribe_link_required`, `copies_not_allowed`, `workflow_can_wait`, `sandbox_fields_unsupported`, `template_not_transactional`, `marketing_template`, `categories_not_enabled` or `recipient_opted_out`.
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
