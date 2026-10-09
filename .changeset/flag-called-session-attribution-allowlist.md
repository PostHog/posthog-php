---
"posthog-php": patch
---

Keep session-attribution properties on minimized `$feature_flag_called` events. `$referring_domain`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `gad_source`, `mc_cid`, and the click-id params posthog-js keeps (`gclid`, `fbclid`, `msclkid`, `ttclid`, and the rest) now survive the minimal-event allowlist, so a minimized event that happens to be a session's first event no longer nulls out that session's attribution in web analytics. Full `$referrer` stays excluded.
