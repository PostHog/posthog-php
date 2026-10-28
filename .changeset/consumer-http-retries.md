---
"posthog-php": patch
---

Retry transient HTTP responses and honor Retry-After in Socket and foreground ForkCurl consumers. Redirects and other unexpected responses count as transient; only 4xx client errors other than 408 and 429 drop the batch. When retries run out on a retryable response, all HTTP consumers now keep the batch queued instead of dropping it, and automatic flushes wait for the maximum backoff duration before they try again. A queue that fills during that wait flushes again once the wait ends.
