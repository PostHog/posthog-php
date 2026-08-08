---
"posthog-php": minor
---

Add an optional `$defaultValue` parameter to `FeatureFlagEvaluations::isEnabled()`. Previously, an unknown/unresolved flag always collapsed to `false` with no way for a caller to distinguish "flag is off" from "flag has no value" — the spec requires the SDK to return a caller-supplied default in that case. `isEnabled($key, defaultValue: true)` now returns `true` when the flag has no resolvable value; a flag with a real value, including `false`, always wins over the default. Existing callers who don't pass it see identical behavior.
