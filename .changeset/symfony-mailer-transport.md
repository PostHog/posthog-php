---
"posthog-php": minor
---

Send Symfony Mailer email through PostHog: set `MAILER_DSN=posthog+api://<project secret key>@default` and register `PostHog\Mailer\PostHogTransportFactory`. Rendered emails, attachments, inline images, and the PostHog options (distinct ID, template key, idempotency key, engagement capture) map to a transactional send, and an email PostHog cannot carry is refused before any request. PostHog adds an open pixel to every email, so an email whose `X-Track` header sets `opens=false` is refused, and any other `X-Track` is dropped. On Symfony Mailer 8.2, a `RemoteTemplateEmail` sends a PostHog template. `EmailError::isRetryable()` is now public.
