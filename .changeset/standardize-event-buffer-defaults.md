---
"posthog-php": patch
---

Fix the default event queue capacity at 10,000 events while retaining the existing 100-event batch size and 5-second opportunistic flush interval.
