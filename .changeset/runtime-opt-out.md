---
"posthog-php": minor
---

Capture: `optOutCapturing()`, `optInCapturing()` and `isOptedOut()` on `PostHog\Client` and the `PostHog` facade stop and resume capture while the process runs. While opted out, `capture()`, `captureException()`, `identify()`, `groupIdentify()`, `alias()` and `raw()` drop the call silently with no event and no network request, and flag evaluation keeps working but sends no `$feature_flag_called`. Events accepted before the opt-out stay queued and are still delivered by `flush()` and `shutdown()`. A client with no project API key always reads as opted out. Clients start opted in, so nothing changes unless you call the new methods.
