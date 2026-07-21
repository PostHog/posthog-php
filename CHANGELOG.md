## 4.12.0

### Minor Changes

- 45c1b23: Send minimal $feature_flag_called events when the server enables the minimal_flag_called_events gate and the flag is not linked to an experiment. Minimal events keep only an allowlisted set of flag evaluation properties; experiment-linked flags, ungated projects, and responses without the signal keep the full event shape. The gate is read from the top-level minimalFlagCalledEvents field of /flags responses and the top-level minimal_flag_called_events field of the local-evaluation flag definitions payload, and persists through external flag definition caches.

## 4.11.0

### Minor Changes

- a657369: Emit error tracking stack frames in the canonical bottom-up order: `stacktrace.frames[0]` is now the outermost/entry-point call and the last frame is the crash site. This aligns the PHP SDK with the cross-SDK stack frame ordering standard.

### Patch Changes

- 67f1b4a: Fix the default event queue capacity at 10,000 events.

## 4.10.0

### Minor Changes

- c6c637c: Add a $feature_flag_has_experiment boolean property to $feature_flag_called events, mirroring the server's has_experiment field. The property is only sent when the server explicitly reports has_experiment and is omitted when unknown (older deployments, missing metadata, missing flags).

## 4.9.0

### Minor Changes

- fa53311: Add a before_send callback for modifying or dropping fully enriched events.

## 4.8.10

### Patch Changes

- 3ab7c80: Stop duplicating `distinct_id` inside `/flags` person properties.

## 4.8.9

### Patch Changes

- 6db62ec: Retry remote feature flag requests after transient 502 and 504 responses.

## 4.8.8

### Patch Changes

- afa2dde: Harden the automated release workflow.

## 4.8.7

### Patch Changes

- b1a5645: Retry capture delivery on transient HTTP errors and respect Retry-After responses.

## 4.8.6

### Patch Changes

- 8974819: Fall back to uncompressed batch uploads when local gzip compression fails.

## 4.8.5

### Patch Changes

- 2f594df: Retry feature flag requests after transient network errors only. The feature flag request retry count defaults to 1 and can be set to 0 to disable retries.

## 4.8.4

### Patch Changes

- a6f28af: Dedupe feature flag called events by response value.

## 4.8.3

### Patch Changes

- 5ed7184: Validate top-level event UUIDs and replace invalid values with generated UUIDs.

## 4.8.2

### Patch Changes

- 21514df: Retain queued batches after network or timeout flush failures.

## 4.8.1

### Patch Changes

- 6e8b141: Copy capture `groups` input to the `$groups` event property so grouped events are associated correctly.

## 4.8.0

### Minor Changes

- fac3a93: Add an external flag definition cache provider for sharing local-evaluation definitions across PHP SDK instances.

## 4.7.0

### Minor Changes

- 3ff0479: Add configurable flush interval support for queued event batching.

## 4.6.1

### Patch Changes

- 0de18c8: Keep the default batch size when invalid non-positive values are configured.
- b7b6cf0: Stop sending deprecated, backend-ignored top-level batch event fields. SDK metadata now uses canonical event properties (`$lib`, `$lib_version`, `$lib_consumer`), while legacy top-level SDK metadata values remain supported as fallbacks when canonical values are absent. `type` is ignored, and `send_feature_flags` remains a deprecated capture option but is stripped from the outgoing `/batch/` payload.

## 4.6.0

### Minor Changes

- 4e0eb86: Support the `early_exit` flag filter in local evaluation. When a flag's `filters.early_exit` is `true` and a condition group's property filters match (or there are none) but the rollout percentage excludes the user, evaluation now stops and returns `false` immediately instead of falling through to later groups. Mirrors the server-side (Rust) evaluation engine. A property-filter mismatch still falls through as before, and behaviour is unchanged when `early_exit` is unset or `false`.

## 4.5.0

### Minor Changes

- aa10180: Add a configurable `$is_server` event property (default `true`) so PostHog can identify server-side events. Set `is_server` to `false` when using posthog-php as a client/CLI so the device OS is attributed normally.

## 4.4.4

### Patch Changes

- ccba73f: No-op facade and feature flag APIs when the SDK is uninitialized or non-operational.

## 4.4.3

### Patch Changes

- e26230c: Move const inside class to prevent memory leaks in worker mode

## 4.4.2

### Patch Changes

- 806dd0b: Initialize a disabled no-op client instead of throwing or sending requests when the API key is missing or blank.

## 4.4.1

### Patch Changes

- 83afee8: Include group context in the `$feature_flag_called` dedupe element so group-scoped flags fire a separate event for each group a user is evaluated under, instead of being dedup-ed against the first group context the same `(distinct_id, flag)` was seen under.

## 4.4.0

### Minor Changes

- 9adcdd6: Add request context helpers for propagating request metadata and optional PostHog tracing headers.
- 9adcdd6: Add an optional `distinctId`/`distinct_id` override for `groupIdentify()` events.

## 4.3.0 - 2026-05-01

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/4.2.4...4.3.0)

- feat(flags): Add `evaluateFlags()` API for single-call flag evaluation. Returns a
  `FeatureFlagEvaluations` snapshot you can read repeatedly without further `/flags` requests; pass
  it to `capture()` via the new `flags` key to attach `$feature/<key>` and `$active_feature_flags`
  on the captured event without an extra round trip.
- feat(flags): Deprecate `isFeatureEnabled()`, `getFeatureFlag()`, `getFeatureFlagResult()`,
  `getFeatureFlagPayload()`, and the `send_feature_flags` `capture()` option in favor of
  `evaluateFlags()`. Each emits an `E_USER_DEPRECATED` warning pointing at the new API; existing
  callers keep working unchanged until the next major version. `getAllFlags()` is intentionally
  _not_ deprecated — it returns an arbitrary key list the snapshot API doesn't yet cover.
- fix(flags): `SizeLimitedHash::contains()` and `add()` were storing entries on the outer map and
  comparing values to keys, so the per-distinct_id `$feature_flag_called` dedup never matched after
  the first event. Both helpers now operate on a per-key set as intended.

## 4.2.4 - 2026-04-28

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/4.2.3...4.2.4)

## 4.2.3 - 2026-04-28

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/4.2.2...4.2.3)

## 4.2.2 - 2026-04-21

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/4.2.1...4.2.2)

## 4.2.1 - 2026-04-21

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/4.2.0...4.2.1)

## 4.2.0 - 2026-04-06

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/4.1.1...4.2.0)

## 4.1.1 - 2026-03-30

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/4.1.0...4.1.1)

## 4.1.0 - 2026-03-30

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/4.0.4...4.1.0)

## 4.0.4 - 2026-03-30

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/4.0.3...4.0.4)

## 4.0.3 - 2026-03-19

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/4.0.2...4.0.3)

## 4.0.2 - 2026-03-05

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/4.0.1...4.0.2)

## 4.0.1 - 2026-02-06

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/4.0.0...4.0.1)

## 4.0.0 - 2026-01-09

- [Full Changelog](https://github.com/PostHog/posthog-php/compare/3.7.3...4.0.0)

  # 3.7.3 / 2025-12-04

- feat(flags): Add ETag support for local evaluation caching
- feat(flags): include `evaluated_at` properties in `$feature_flag_called` events

  # 3.7.2 / 2025-10-22

  - fix(flags): fallback to API for multi-condition flags with static cohorts (#86)

  # 3.7.1 / 2025-09-26

  - fix: don't sort condition sets with variant overrides to the top (#85)

  # 3.7.0 / 2025-08-26

  - feat(flags): Implement local evaluation of flag dependencies (#84)
  - fix: Ignore new `flag` filter type in local evaluation (#80)
  - chore: Add feature flags project board workflow (#79)

  # 3.6.0 / 2025-04-30

  - chore(flags): use new `/flags` endpoint instead of `/decide` (#76)

  # 3.5.0 / 2025-04-17

  - feat: Add request id, version, id, and evaluation reason to $feature_flag_called events (#75)
  - Bump version to 3.4.0 (#74)
    3.4.0 / 2025-04-15
    ==================

  - feat(flags): Add getFeatureFlagPayload method (#53)

  # 3.3.5 / 2025-03-26

  - Fix version updating in Makefile (#72)

  # 3.3.4 / 2025-03-11

  - Add support for 'verify_batch_events_request=>false' (#70)
  - Run GitHub actions on all supported PHP versions (#67)

  # 3.3.3 / 2025-02-28

  - Fix PHP 8.4 deprecation on Client.php constructor (Backwards Compatible) (#66)

  # 3.3.2 / 2024-04-03

  - Make the feature flag fetch optional on initialisation (#65)

  # 3.3.1 / 2024-03-22

  - fix(flags): Handle bool value matching (#64)
  - Fixes a bug with local evaluation where passing in true and false values for a property wouldn't match correctly.

  # 3.3.0 / 2024-03-13

  - feat(flags): Locally evaluate all cohorts (#63)

  # 3.2.2 / 2024-03-11

  - feat(flags): Add specific timeout for feature flags (#62)
  - Adds a new `feature_flag_request_timeout_ms` timeout parameter for feature flags which defaults to 3 seconds, updated from the default 10s for all other API calls.

  # 3.2.1 / 2024-01-26

  - fix(flags): Update relative date op names (#61)
  - Remove new relative date operators, combine into regular date operators

  # 3.2.0 / 2024-01-10

  - feat(flags): Add local props and flags to all calls (#60)
  - When local evaluation is enabled, we automatically add flag information to all events sent to PostHog, whenever possible. This makes it easier to use these events in experiments.

  # 3.1.0 / 2024-01-10

  - feat(flags): Add relative date operator and fix numeric ops (#58)
  - Numeric property handling for feature flags now does the expected: When passed in a number, we do a numeric comparison. When passed in a string, we do a string comparison. Previously, we always did a string comparison.
  - Add support for relative date operators for local evaluation.
  - Fixes issue with regex matching for local evaluation.

  # 3.0.8 / 2023-09-25

  - fix(flags): Safe access flags in decide v2 (#55)

  # 3.0.7 / 2023-08-31

  - PHP 8.1+ Support + Fix Errors When API/Internet Connection Down (#54)

  # 3.0.6 / 2023-07-04

  - Fix typehint (#52)

  # 3.0.5 / 2023-06-16

  - Prevent "Undefined array key" warning in isFeatureEnabled() (#51)

  # 3.0.4 / 2023-05-19

  - fix(flags): Handle no rollout percentage condition (#49)

  # 3.0.3 / 2023-03-21

  - Merge branch 'master' into groups-fix
  - Make timeout configurable (#44)
  - format
  - fix(groups): actually add groups support for capture

  # 3.0.2 / 2023-03-08

  - update version 3.0.2
  - Allow to configure the HttpClient maximumBackoffDuration (#33)

  # 3.0.1 / 2022-12-09

  - feat(flags): Add support for variant overrides (#39)
  - Update history (#37)

  # 3.0.0 / 2022-08-15

  - Requires posthog 1.38
  - Local Evaluation: isFeatureEnabled and getFeatureFlag accept group and person properties now which will evaluate relevant flags locally.
  - isFeatureEnabled and getFeatureFlag also have new parameters:
    onlyEvaluateLocally (bool) - turns on and off local evaluation
    sendFeatureFlagEvents (bool) - turns on and off $feature_flag_called events
  - Removes default parameter from isFeatureEnabled and getFeatureFlag. Returns null instead

  # 2.1.1 / 2022-01-21

  - more sensible default timeout for requests
  - Merge pull request #29 from PostHog/group-analytics-flags
  - Add groups feature flags support
  - Test default behavior
  - Release 2.1.0
  - Merge pull request #26 from PostHog/group-analytics-support
  - Add basic group analytics support
  - Fix bin/posthog help text
  - Allow bypassing ssl in bin/ command
  - Solve linter issues

  # 2.1.0 / 2021-10-28

  - Add basic group analytics support
  - Fix bin/posthog help text
  - Allow bypassing ssl in bin/ command

  # 2.0.6 / 2021-10-05

  - Separate timeout from maxBackoffDuration
  - Set the timeout config for HttpClient curl

  # 2.0.5 / 2021-07-13

  - Merge pull request #23 from joesaunderson/bugfix/send-user-agent
  - Send user agent with decide request

  # 2.0.5 / 2021-07-13

  # 2.0.4 / 2021-07-08

  - Release 2.0.3
  - Merge pull request #21 from joesaunderson/bugfix/optional-apikey
  - API key is optional
  - Merge pull request #20 from imhmdb/patch-1
  - Fix calling error handler Closure function stored in class properties

  # 2.0.3 / 2021-07-08

  - Merge pull request #21 from joesaunderson/bugfix/optional-apikey
  - API key is optional
  - Merge pull request #20 from imhmdb/patch-1
  - Fix calling error handler Closure function stored in class properties

  # 2.0.2 / 2021-07-08

  - Merge pull request #19 from PostHog/handle-host
  - fix tests for good
  - check if host exists before operating on it
  - undefined check
  - fix tests
  - Allow hosts with protocol specified
  - Merge pull request #18 from PostHog/feature-flags
  - remove useless comment
  - have env var as the secondary option
  - bump version
  - bring back destruct
  - remove feature flags
  - simplify everything
  - Cleanup isFeatureEnabled docblock
  - Fix user agent undefined array key
  - Merge pull request #17 from PostHog/releasing-update
  - Note `git-extras` in RELEASING.md
  - Add test case for isFeatureEnabled with the simple flag in the mocked response
  - Fix: make rolloutPercentage nullable in isSimpleFlagEnabled
  - Merge remote-tracking branch 'upstream/master'
  - Fix is_simple_flag tests by mocking response
  - Use LONG_SCALE const
  - Implement isSimpleFlagEnabled
  - Don't set payload on get requests
  - (WIP) Rework feature flags based on spec `https://github.com/PostHog/posthog.com/pull/1455`
  - Extract http client functionalities
  - Remove extra line
  - Change default host to app.posthog.com
  - Feature/support feature flags decide API
  - Upgrade phplint
    2.0.1 / 2021-06-11
    ==================

  - Allow for setup via environment variables POSTHOG_API_KEY and POSTHOG_HOST
  - Make code adhere to PSR-4 and PSR-12

  # 2.0.0 / 2021-05-22

  - fix sed command for macos
  - Merge pull request #9 from adrienbrault/psr-4
  - Finish psr-4 refactoring
  - PostHog/posthog-php#3: Update composer.json to support PSR-4
  - Update README.md
  - Merge pull request #6 from chuva-inc/document_property
  - Merge pull request #5 from chuva-inc/issue_4
  - Posthog/posthog-php#3: Document the customer property
  - PostHog/posthog-php#4: Removes
    from beginning of the file
  - Update README.md
  - fix infinite loop on error 0 from libcurl
  - fix error when including https:// in host
  - fix tests for php 7.1, phpunit 8.5
  - upgrade phpunit and switch php to >=7.1
  - Update README.md
  - make tests pass
  - first commit
