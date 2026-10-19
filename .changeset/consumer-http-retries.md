---
"posthog-php": patch
---

Retry transient HTTP responses and honor Retry-After in Socket and foreground ForkCurl consumers. Retry-After waits are now capped at 10 seconds in all HTTP transports.
