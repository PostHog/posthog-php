---
"posthog-php": minor
---

Add a `disableGeoip` option to `PostHog::groupIdentify()`. When it is `true`, the `$groupidentify` event carries `$geoip_disable`, so PostHog skips GeoIP enrichment for it.
