---
"posthog-php": patch
---

Add a configurable `$is_server` event property (default `true`) so PostHog can identify server-side events. Set `is_server` to `false` when using posthog-php as a client/CLI so the device OS is attributed normally.
