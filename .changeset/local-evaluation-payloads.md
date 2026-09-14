---
"posthog-php": patch
---

Resolve feature flag payloads during local evaluation from the definition's `filters.payloads`, so `evaluateFlags()->getFlagPayload()` and `getFeatureFlagPayload()` no longer return null for locally evaluated flags.
