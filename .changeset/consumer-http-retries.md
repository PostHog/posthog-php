---
"posthog-php": patch
---

Retry transient HTTP responses and honor Retry-After in Socket and foreground ForkCurl consumers. Retry-After waits are now capped at 10 seconds in all HTTP transports. When retries run out on a retryable response, all HTTP consumers now keep the batch queued instead of dropping it, and automatic flushes wait for the maximum backoff duration before they try again. A queue that fills during that wait flushes again once the wait ends.
