---
"posthog-php": minor
---

Report the flags local evaluation could not resolve on `evaluateFlags()` snapshots. `FeatureFlagEvaluations::getUnresolvedFlags()` maps each such flag key to an `UnresolvedFlagReason` value: `experience_continuity`, `unsupported_definition`, `unresolved_dependency` or `missing_context`.

Behavior changes:

- A condition with a filter that local evaluation cannot check and a filter that does not match now resolves locally as no match. An inconclusive flag dependency in one condition, or a dependency that needs server data such as a static cohort, no longer stops a later condition from resolving the flag. So a flag that went to the server before can now resolve locally.
- An inactive flag with experience continuity enabled now resolves to `false` locally instead of going to the server.
- A flag that depends on a flag with experience continuity enabled no longer resolves locally. It goes to the server, or stays unresolved with `onlyEvaluateLocally`.
- A malformed date in a flag filter or a person property now makes its condition inconclusive, like other malformed values, instead of failing local evaluation of the whole flag.
- `$feature_flag_called` events from snapshot reads of unresolved flags report `local_evaluation_inconclusive` instead of `flag_missing`.
- When a fresh flag definitions response (HTTP 200) loads, the SDK logs a warning, also with `debug` off, that names the flags local evaluation can never resolve: flags with experience continuity, and flags that use a static cohort, an unknown operator, a malformed value, or a missing or circular dependency. Each flag is warned about once per client until its definition changes. Definitions from a cache provider or a 304 response do not log the warning.
