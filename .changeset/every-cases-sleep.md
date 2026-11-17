---
"posthog-php": minor
---

FeatureFlagEvaluations::isEnabled() takes an optional defaultValue, returned when the snapshot holds no value for the flag; a flag that has a value, including false, still wins.
