---
"posthog-php": patch
---

Relative date filters in local flag evaluation no longer roll past the end of a shorter month. `1m` on March 31 now resolves to February 28 instead of March 3.
