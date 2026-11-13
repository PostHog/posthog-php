---
"posthog-php": minor
---

The new `getRemoteConfigPayload()` reads the payload of a remote config feature flag, for example `PostHog::getRemoteConfigPayload('landing-page-config')`. It needs a secret key and sends one request without a retry. It returns the decoded payload, or `null` and a logged warning when the client is disabled, no secret key is set, the flag key is empty or is `.` or `..`, the request fails, PostHog answers with a non-2xx status, or the response body is not valid JSON. It sends no `$feature_flag_called` event. It uses the `feature_flag_request_timeout_ms` option, 3 seconds by default.
