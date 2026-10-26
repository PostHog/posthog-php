---
"posthog-php": patch
---

Stop the Socket consumer from resending a batch forever when the server answers with a redirect or another response it cannot retry.
