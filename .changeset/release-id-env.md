---
"posthog-php": minor
---

Read the release id from the `POSTHOG_RELEASE_ID` environment variable and send it as `$release_id` on every event, including minimal `$feature_flag_called` events. On `$exception` events, error tracking uses it to link the exception to its release by a direct id lookup. Create the release and get its id with `posthog-cli release resolve`. An explicit `$release_id` in the event properties or in the request context wins over the environment variable. Under PHP-FPM, pass the variable through to workers (`clear_env = no` or an `env[POSTHOG_RELEASE_ID]` pool entry).
