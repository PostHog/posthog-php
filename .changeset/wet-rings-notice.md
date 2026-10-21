---
"posthog-php": patch
---

Honor configured ingestion timeouts in the libcurl and forked curl transports, rounding positive fractional milliseconds up so they remain bounded.
