---
"posthog-laravel": minor
---

Send Mailables and mail notifications through PostHog: add a mailer with `'transport' => 'posthog'` in `config/mail.php` and set `MAIL_MAILER=posthog`. Each mail sends under its Mailable or notification class in kebab case as its template key, also through failover, round-robin and on-demand mailers; `X-PostHog-*` headers set the template key, distinct ID and idempotency key. Templates made in PostHog are not supported.
