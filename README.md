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

`emails()` sends one email to one recipient, or the same email to many recipients, one email each, from an email template made transactional in PostHog or from content your code rendered, and reads what happened to it.

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

- **Two forms.** Pass `template_id` with optional `template_data`, or `template_key` with `subject` and `html`, `text` or both. The content form also takes `from` (a verified sender), `reply_to`, `cc`, `bcc`, `attachments`, `sensitive` and `tracking`. PostHog derives a missing `text` from the `html`. To send an email built with a template library such as React Email, render it to HTML in your app and pass `html`. The SDK adds no rendering dependency.
- **Copies and files.** `cc` and `bcc` take one address or a list of up to 10. `attachments` takes up to 10 files, each with `filename`, `content` (the file's bytes) and optional `content_type` and `content_id`. The whole email can be at most 40 MB after Base64 encoding, which leaves about 29 MB for the files. The 40 MB counts the subject and the bodies, and without `text` it counts the `html` twice, since PostHog sends the text it derives from it. The SDK checks both limits before any request (`too_many_attachments`, `message_too_large`). Pass the bytes, not Base64: when you hold Base64, such as from a Resend payload, pass `base64_decode($content)`. Show a file inline with `<img src="cid:logo">` and `'content_id' => 'logo'`. A PostHog version that doesn't take these fields yet refuses the send with an error naming the field, and never drops it.
- **How files travel.** The SDK hashes each file with SHA-256, asks PostHog which files it still needs, uploads those straight to PostHog's file storage with the URL and headers PostHog answers (never with your secret key), then sends the email with each file's hash. A file the project already uploaded isn't uploaded again. The storage host differs from your app host, so allow it if your servers restrict outbound traffic. Files go only to an `https` storage URL, unless your `app_host` is plain `http://` for local development. A storage 429, 500, 502, 503 or 504 is retried like the send. A failed upload throws `attachment_upload_failed` with the storage's answer, and no email is sent. An upload never follows a redirect: storage that answers one fails that file the same way, without naming the URL.
- **Sensitive emails.** Pass `'sensitive' => true` when the email carries login links, reset tokens or codes. A sensitive email keeps its links unchanged unless `tracking` turns clicks on, leaves its subject off engagement events, and keeps its content out of logs. Left out, the email takes the setting of the template made in PostHog whose key matches `template_key`, and is sensitive when no template matches.
- **Tracking.** Left out, PostHog counts the email's opens and captures its engagement events, and tracks clicks only when the email isn't sensitive. `'tracking' => true` turns on opens, clicks and engagement events. `'tracking' => false` turns them all off: no open pixel, no rewritten links, no engagement events. An array such as `['clicks' => true]` sets `opens` (default `true`), `clicks` (default: on unless the email is sensitive) and `engagement` (default `true`) one by one. Turn clicks on for a sensitive email only when its links are safe to rewrite. Click tracking needs open tracking. `capture_engagement` is deprecated in favor of `tracking` with `engagement`. It still works, but a send can't set both.
- **Pass `distinct_id`.** Without it, the email's engagement events go to a person whose distinct ID is the email address.
- **Scheduling.** Pass `scheduled_at`, a `DateTimeInterface` or an ISO 8601 string with an offset such as `2026-12-01T09:00:00Z`, to send the email up to 7 days later. A string without an offset throws `invalid_scheduled_at` before any request. The SDK rounds a time finer than a millisecond up to the next millisecond, so the email never goes out early. Only PostHog Cloud schedules emails: with any other `app_host`, such as a self-hosted PostHog, a reverse proxy or a custom domain, a send with `scheduled_at` throws `scheduling_requires_cloud` before any request. The result's `getStatus()` is `scheduled` with the confirmed `getScheduledAt()`, or `queued` when the time had already passed and the email goes out now. When PostHog accepts the email without confirming the time, the send throws `schedule_not_confirmed`, and so does a `queued` answer for a time more than a minute ahead of your server's clock when the send's first attempt went out, even when a retry gets the answer (the minute allows for clock difference); read the email's status with `getMessageId()` before you send it again. `get()` reads `scheduled` until the email goes out. Compute a relative time once and reuse it on your own retries: the idempotency key covers the time. To stop scheduled emails, disable the email's workflow in PostHog until they have reached a final status.
- **A result means accepted**, not delivered. `send()` returns a `SendEmailResult` with the message ID, and `getStatus()` says whether PostHog queued or scheduled the email. `get()` reads the status for 30 days after the send. For a few seconds after the send, it throws `not_found`.
- **Errors.** A send that is not queued or scheduled throws `PostHog\EmailError`, and so does a scheduled send whose time PostHog didn't confirm (`schedule_not_confirmed`, with `getMessageId()`). Branch on `getErrorCode()`, such as `recipient_suppressed` or `missing_app_host`, or on `getErrorName()`, such as `validation_error`. An invalid argument throws before any request, with an `invalid_*` code.
- **Retries.** Network errors, rate limits and an unavailable service are retried up to `email_request_max_retries` times (default 2) with the same idempotency key, so a retry never sends twice. Each request times out after `email_request_timeout_ms` (default 15000). When a job runner already retries the send, pass `'max_retries' => 0` and an `idempotency_key` derived from what the email is for, such as `welcome:user_123`. A generated key does not survive a job retry.
- **Moving from another provider.** Set the `migrated_from` option, such as `'resend'`, so PostHog knows where your sends came from.

### Batches

`batch()` sends the same email to many recipients, one email each, and returns a result per recipient in the order of `recipients`:

```php
// $digestId is stored with the job, so a retry of the job makes the same keys
$result = PostHog::emails()->batch([
    'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b',
    'template_data' => ['team' => 'Analytics'], // shared by every recipient
    'recipients' => [
        ['to' => 'ada@example.com', 'template_data' => ['name' => 'Ada'], 'idempotency_key' => "$digestId:ada"],
        ['to' => 'alan@example.com', 'template_data' => ['name' => 'Alan'], 'idempotency_key' => "$digestId:alan"],
    ],
]);
foreach ($result->getResults() as $email) {
    if ($email->getStatus() !== 'queued') {
        echo $email->getTo(), ': ', $email->getStatus(), PHP_EOL;
    }
}
```

- **Recipients.** Each recipient has `to` and optional `distinct_id`, `idempotency_key` and `template_data`. A recipient's `template_data` is merged over the shared `template_data`, and its keys replace the shared ones. With `template_key`, the content is the same for every recipient, so a recipient can't carry `template_data`. A batch takes no `cc`, `bcc`, `attachments` or `scheduled_at`: send those emails with `send()`.
- **Requests.** A batch takes 1 to 100 recipients and goes out in one request. The client waits at least 45 seconds for the answer. More than 100 recipients throws `invalid_recipients` before any request.
- **Idempotency keys.** Every retry reuses each recipient's key, so a retry never queues an email twice. Without a key, the client makes one that covers its own retries but not a second call. When a batch may be cut off and run again, pass keys you store: send it again with the same key within 24 hours of its first use, so it goes out once. Each recipient of a call needs a different key. Build each key from an ID you store with the job, such as the digest's own ID, never from the time of the run: a retry after midnight would make new keys and send the emails again. Never reuse a key for another email: PostHog sends one email per key, so the other doesn't go out. PostHog usually refuses it with `idempotency_key_reused`, but two sends that arrive at the same time can both answer `queued`.
- **Statuses.** `getStatus()` is `queued` when PostHog accepted the email, `failed` when it refused it (`getError()` says why, such as a suppressed address), and `unconfirmed` when the client can't tell, such as after a lost answer or a 409 other than `batch_unavailable`. Send each `unconfirmed` email again with the same key within 24 hours of its first use, so it goes out once. `getWarnings()` holds PostHog's warnings.
- **Errors.** When PostHog refuses the batch as a whole, such as a rate limit past its retries, `batch()` throws `PostHog\EmailBatchError`, whose `getResults()` holds every recipient. `getFix()` carries PostHog's advice. `content_too_large` with status 413 means the request is larger than PostHog takes: split the recipients into smaller batches, or send a smaller email. A 413 without a code, such as a proxy's, reads the same. With status 400 it means the content or `template_data` every recipient shares is too large, so fewer recipients won't help: make it smaller, or send those emails one at a time with `send()`. `batch_unavailable` (409) means the project can't send batches yet, and is never retried: send each email the batch didn't queue with `send()` and its recipient's `idempotency_key`. `not_found` (404) without a field means PostHog found no template with that `template_id`, or batch sends aren't available for the project. Check the `template_id`. If it's right, send each recipient on its own with `send()` and its `idempotency_key` until batch sends are available. When an earlier attempt got no answer, a refusal of the retry doesn't prove nothing went out: the error says some emails may have gone out, every recipient stays `unconfirmed`, and `getFix()` says to send each one again with the same `idempotency_key`.

## Questions?

### [Join our Slack community.](https://join.slack.com/t/posthogusers/shared_invite/enQtOTY0MzU5NjAwMDY3LTc2MWQ0OTZlNjhkODk3ZDI3NDVjMDE1YjgxY2I4ZjI4MzJhZmVmNjJkN2NmMGJmMzc2N2U3Yjc3ZjI5NGFlZDQ)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for local setup and test instructions.

## Releasing

See [RELEASING.md](RELEASING.md).
