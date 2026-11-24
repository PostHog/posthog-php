---
"posthog-php": minor
---

Transactional email: `emails()->send()` takes `scheduled_at` to send an email up to 7 days later, on PostHog Cloud only. The result has `getStatus()` (`scheduled` or `queued`) and `getScheduledAt()`, `get()` can read `scheduled`, and `EmailError` has `getMessageId()` for an accepted email whose time PostHog didn't confirm, including one PostHog queued now for a time more than a minute ahead.
