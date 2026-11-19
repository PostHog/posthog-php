---
"posthog-php": minor
"posthog-laravel": minor
---

Flag evaluation methods take optional `personPropertyKeysKnownAbsent` and `groupPropertyKeysKnownAbsent` arguments listing property keys known to be absent. Local evaluation resolves a listed key without falling back to `/flags`: `is_not_set`, `is_not` and the `not_*` string operators match, and every other operator doesn't. A remote request carries the keys. A list over 1000 keys throws `InvalidArgumentException`.
