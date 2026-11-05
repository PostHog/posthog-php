---
"posthog-php": minor
---

Transactional email: `emails()->send()` takes an `integration` option, `<name>/<version>` such as `laravel/1.4.0`, that a mailer integration passes to name itself on the send. The client sends it as the `X-PostHog-Integration` header; the User-Agent stays the same.
