---
"posthog-php": minor
---

Support the `starts_with`, `not_starts_with`, `ends_with`, and `not_ends_with` property filter operators in feature flag local evaluation. Matching is case-insensitive and mirrors `icontains`. Previously, local evaluation treated these operators as unrecognized and silently evaluated their conditions to `false`; they now match correctly.

Operators local evaluation doesn't recognize now throw `InconclusiveMatchException`, deferring the flag to the `/flags` endpoint instead of producing a silently wrong `false` — so operators the server adds in the future degrade gracefully.
