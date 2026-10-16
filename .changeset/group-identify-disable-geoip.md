---
"posthog-php": minor
---

Add a client-wide disableGeoip option and per-call overrides for events and remote feature flag evaluation. Keep the default false for compatibility with existing PHP releases; set disableGeoip to true to opt into Node-style GeoIP behavior.
