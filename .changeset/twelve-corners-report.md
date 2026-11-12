---
"posthog-php": patch
---

Report evaluation_failed instead of flag_missing for failed direct flag reads, including inconclusive local fallback, while retaining response-wide errors.
