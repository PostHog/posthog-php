---
"posthog-php": patch
---

Local evaluation now treats a group key that isn't a non-empty string or a number, such as `''`, `null`, a boolean or an array, as no key for its group type, like the flags service. A group flag with such a key resolves to `false` locally without a `/flags` request, a condition on that group type is skipped, and the key is never used for bucketing. This also fixes local results without known-absent keys.
