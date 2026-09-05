---
posthog-php: patch
---

Honor the definitions snapshot's `property_matching_version` during local feature flag evaluation, including group, cohort, and flag dependency conditions. Version 2 uses explicit boolean matching; missing or other versions retain legacy matching. Preserve the selector across external definition caches and reloads.
