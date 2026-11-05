---
"posthog-php": minor
---

The new `emails()` sends transactional email from your PHP code and reads each email's delivery status.

```php
PostHog::init('phc_...', ['host' => 'https://us.i.posthog.com'], secretKey: 'phs_...');

$result = PostHog::emails()->send([
    'to' => 'user@example.com',
    'distinct_id' => 'user_123',
    'template_key' => 'password-reset',
    'subject' => 'Reset your password',
    'html' => $renderedHtml,
]);

// 'delivered', 'bounced', ...
$status = PostHog::emails()->get($result->getId())->getStatus();
```

You can also send a template made in PostHog by its `template_id`.

You need a project secret API key, the kind that starts with `phs_`. Create one under **Project secret API keys** in your project settings. Set its **Transactional email** scope to Write to send emails, or to Read for a key that only reads statuses. Pass it to the client as `secretKey`. Set the `app_host` option to your PostHog app URL when you self-host PostHog or send through a reverse proxy. The client also loads feature flags with this key when it starts, so give it **Feature flags: Read** too, or it logs an error each time a client starts.

PostHog records each email's delivery, opens and clicks on the person with that `distinct_id`. It groups every email with the same `template_key`, so you see their metrics and logs in one place. The SDK retries a request that hits a network error, a rate limit or an outage twice by default. Every send carries an idempotency key, so a retry never sends the email twice. Pass your own `idempotency_key`, such as `welcome:user_123`, and your job runner's retries are safe too.

A send with your own content also takes `from`, `reply_to` and `'capture_engagement' => false`. Set the `migrated_from` option to tell PostHog which email provider you moved from. A failed call throws a `PostHog\EmailError`. Its `getErrorCode()` returns PostHog's code, and `getStatusCode()` returns the HTTP status.

Docs: [Send transactional emails with PHP](https://posthog.com/docs/workflows/transactional-emails/php) and the [transactional email overview](https://posthog.com/docs/workflows/transactional-emails).
