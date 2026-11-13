---
"posthog-php": minor
---

The new `getRemoteConfigPayload()` reads the payload of a remote config feature flag by its key, with no distinct ID. It needs the `secretKey` the client was created with. It returns `null` and logs a warning on a failure.
