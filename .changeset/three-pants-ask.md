---
"posthog-php": minor
---

The new `getRemoteConfigPayload()` reads the payload of a remote config feature flag, for example `PostHog::getRemoteConfigPayload('landing-page-config')`. It needs a secret key and sends one request without a retry. It returns the decoded payload, or `null` and a logged warning when the client is disabled, no secret key is set, the request fails, or PostHog answers with a non-2xx status. It sends no `$feature_flag_called` event. It uses the `feature_flag_request_timeout_ms` option, 3 seconds by default.
