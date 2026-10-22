---
"posthog-php": patch
---

Serialize non-finite event property numbers as null so they do not discard events or batches.
