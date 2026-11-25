---
"posthog-php": patch
---

Local evaluation now evaluates a flag dependency as if the referenced flag were evaluated directly with the same inputs, with its own aggregation, properties and bucketing identifier. A person flag that depends on a group flag reads the group's properties and hashes the group key, and a dependency on a group flag whose group isn't supplied is `false`. This also fixes local results without known-absent keys.
