---
"posthog-php": minor
---

Support the `starts_with`, `not_starts_with`, `ends_with`, and `not_ends_with` property filter operators in feature flag local evaluation. Matching is case-insensitive and mirrors `icontains`. Previously, local evaluation treated these operators as unrecognized and silently evaluated their conditions to `false`; they now match correctly.
