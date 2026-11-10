---
"posthog-php": minor
---

A send with your own content (`template_key`) takes `tracking` to choose what PostHog tracks for the email. Left out, PostHog counts opens and captures engagement events, and doesn't track clicks. `true` turns on opens, clicks and engagement events, and `false` turns them all off. An array such as `['clicks' => true]` sets `opens`, `clicks` and `engagement` one by one. `capture_engagement` is deprecated in favor of `tracking` with `engagement`; it still works, but a send can't set both. The client sends `tracking` only when you set it. It needs PostHog's API support for the field, which is rolling out.
