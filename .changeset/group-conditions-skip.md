---
"posthog-php": patch
---

Local evaluation now skips a condition aggregated by a group without a usable key one condition at a time, like the flags service, instead of resolving the whole group flag to `false`. A person condition (`aggregation_group_type_index: null`) in a group flag still runs, also when the flag is a dependency. A group flag with a holdout above 0% and no usable group key is inconclusive with reason `missing_context`, so it falls back to `/flags` unless only local evaluation is allowed.
