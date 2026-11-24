---
"posthog-php": minor
---

Transactional email: `emails()->batch()` sends the same email to many recipients, one email each, from a template with `template_data` per recipient or from content in your code. A call takes up to 100 recipients and sends one request. The client gives each recipient an idempotency key that every retry reuses, and returns a `SendBatchResult` with a `BatchEmailResult` per recipient: `queued`, `failed` or `unconfirmed`. A batch PostHog refuses as a whole, such as `content_too_large`, throws `EmailBatchError` with every recipient's result.
