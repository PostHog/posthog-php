---
"posthog-php": minor
---

A send with your own content (`template_key`) takes `'track_opens_and_clicks' => false` to send the email without open and click tracking: no open pixel, and no links rewritten to count clicks. PostHog then can't count that email's opens and clicks. It defaults to `true` and is independent of `capture_engagement`, which decides whether engagement events are captured on the recipient's person. The client sends the field only when you set it. It needs PostHog's API support for the field, which is rolling out.
