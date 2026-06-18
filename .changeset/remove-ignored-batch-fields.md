---
"posthog-php": patch
---

Stop sending deprecated, backend-ignored top-level batch event fields. SDK metadata now uses canonical event properties (`$lib`, `$lib_version`, `$lib_consumer`), while legacy top-level SDK metadata values remain supported as fallbacks when canonical values are absent. `type` is ignored, and `send_feature_flags` remains a deprecated capture option but is stripped from the outgoing `/batch/` payload.
