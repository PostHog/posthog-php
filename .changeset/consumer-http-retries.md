---
"posthog-php": patch
---

Retry transient HTTP responses and honor Retry-After in Socket and foreground ForkCurl consumers. Retry-After waits are now capped at the maximum backoff duration in all HTTP transports.
