---
"posthog-php": patch
---

Fix local feature flag `gt`, `gte`, `lt`, and `lte` comparisons to compare numeric strings numerically, which can change flag membership for numeric-string properties. Invalid property values no longer compare lexicographically; invalid numeric filters fall back to remote evaluation.
