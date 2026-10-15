---
"posthog-php": patch
---

Match `regex` and `not_regex` flag filters locally the way the flags service does. The filter value is now always used as the pattern itself, so patterns containing `/` (such as `^https://example\.com/`) or starting with a bracket (such as `[abc]`) no longer evaluate to `false`, and slash-wrapped values like `/abc/i` are matched literally. Property values are stringified like the other string operators (`null`, booleans, floats, and arrays included), so an array property no longer throws a `TypeError` out of `getFeatureFlag`. Patterns run in Unicode mode, and a regex execution failure is now inconclusive instead of silently making `not_regex` match.
