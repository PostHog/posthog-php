---
"posthog-php": minor
---

A send with your own content (`template_key`) takes `'track_opens_and_clicks' => false` to send the email without PostHog's open pixel, so PostHog can't count its opens. PostHog doesn't track clicks on emails sent from code, so their links are never rewritten either way. It defaults to `true` and is independent of `capture_engagement`, which decides whether engagement events are captured on the recipient's person. The client sends the field only when you set it. It needs PostHog's API support for the field, which is rolling out.
