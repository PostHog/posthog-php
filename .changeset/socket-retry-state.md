---
"posthog-php": patch
---

Stop the Socket consumer from resending a batch forever when the server answers with a redirect. Socket now retries redirects and other unexpected responses with backoff, and stops at the backoff limit.
