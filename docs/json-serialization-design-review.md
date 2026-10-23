# PHP event serialization: design checkpoint

## Status and scope

- PR: https://github.com/PostHog/posthog-php/pull/276
- Evaluated revision: `577a8b72c0c38906d1390772c189ac614d5d0d57`.
- Worktree: `/Users/marandaneto/Github/worktrees/posthog-php-nonfinite-20261022-123914`.
- The user subsequently approved the conservative, data-only repair direction with “go for it.” The proposed contract below is now implemented as documented in [Event JSON encoding](json-serialization.md).
- The original checkpoint evidence and reproduction inventory below are retained as a historical baseline. The new implementation does not reconstruct Zend serialization behavior: it either repairs a bounded callback-free data tree or delegates the original whole payload to native JSON.

## What went wrong

The review threads are not repetitions of the same unresolved comment. New revisions have exposed different violations of the same underlying invariants: native property views and ordering, callback execution, array identity, caller ownership, and resource use.

The original recursive-wrapper approach introduced snapshots and retained wrappers. Fixes added weak references, native-type handling, preflight scans and budgets. The sequential writer removed the wrapper graph but assumed responsibility for Zend's property traversal, recursion detection, primitive buffering and error state. That moved the failure surface rather than establishing a stable contract.

Passing the accumulated regression suite showed that individual examples had been repaired. It did not establish that either implementation was a native-equivalent JSON encoder. In particular:

- A test asserting recursion failure without checking callback counts missed duplicate execution and stateful acceptance of a cycle.
- Ordinary inheritance tests did not cover abstract virtual properties becoming backed slots.
- Ordinary native-object tests did not cover a property-view read changing a DateInterval object's subsequent native output.
- Sibling-container memory probes did not cover duplication of a single large encoded string.

The first implementation commit, `bbc0f54`, already tested normalization inside stdClass, a typed readonly object and JsonSerializable output. Object normalization was therefore part of the original promise, not merely accidental scope added during review. Narrowing it now requires explicit agreement.

## Contract assessed at the checkpoint

### Compatibility requirements

For inputs with no non-finite numbers, native `json_encode()` is the independent oracle. Compare fresh equivalent fixtures, including:

1. Wire values, object/list distinctions and omission of uninitialized or removed properties.
2. Operational property order whenever getters or serializers affect later values, especially redaction.
3. Callback/getter invocation counts, ordering and propagated user exceptions.
4. Native recursion/depth rejection, JSON error state and absence of partial output.
5. Caller-visible state after encoding. User callbacks may intentionally mutate state; the SDK must not add mutations beyond native behavior.
6. Material memory/time regressions, including normal rejection by the existing batch-size guard rather than a process-fatal allocation before that guard.

These requirements are distinct from the feature expansion: converting an otherwise unsupported non-finite number into JSON null without changing the caller's value.

### Behavior requiring an explicit scope decision

| Input category | Before this PR | Promise before the checkpoint | Approved conservative contract |
|---|---|---|---|
| Eligible scalar/array JSON data containing NaN or infinity | Native encoding fails | Convert numbers to null recursively | Preserve this core feature within a defined safe domain |
| Finite custom/native objects, hooks and reference graphs | Native traversal and handlers | Native-equivalent behavior | Delegate the original payload to native encoding unchanged |
| Non-finite values inside objects, getters, serializer results or reference cells | Native failure or user exception | Also normalize these values | Keep native behavior outside the safe domain; this narrows the original promise |
| A batch mixing opaque values and otherwise repairable non-finite data | Native batch failure | Preserve healthy neighboring events | Whole-payload native fallback may still fail the batch; this limitation needs agreement |
| Malformed UTF-8, resources and actual cycles | Native errors | No partial JSON | Preserve native error handling rather than introduce synthetic successful output |

The [capture spec](https://github.com/PostHog/sdk-specs/blob/main/openspec/specs/capture/spec.md) covers capture, serialization safety and a separate null/undefined-property cleanup contract. It does not define PHP object traversal or this NaN/infinity policy. Do not infer a new non-finite policy from the null-member rule or mix another cleanup into this reassessment.

## Design alternatives

### 1. Continue the general-purpose writer

This preserves the broad promise only if we can demonstrate native-equivalent behavior across the supported PHP versions. The current implementation has not demonstrated that.

Its risky responsibilities include:

- Reconstructing stored-slot order from reflection metadata, including virtual/abstract implementations.
- Selecting native extension property views without materializing extra caller state.
- Treating reference-cell IDs as a substitute for native array identity across callbacks. [ReflectionReference::getId](https://www.php.net/manual/en/reflectionreference.getid.php) identifies a reference, not a copy-on-write array value.
- Preserving native callback execution while detecting cycles before re-entry.
- Avoiding a second full string allocation while assembling the output.

**Not recommended as another incremental patch round.** Four more example-specific fixes would not prove these invariants.

### 2. Try native JSON first, then retry after normalization

Rejected for stateful values. A local probe with a JsonSerializable returning NaN recorded two callback invocations for two native attempts. Retrying after a failed first pass can observe changed data, repeat side effects or alter redaction behavior.

This is not a safe fallback for arbitrary objects or reference graphs.

### 3. Use partial output or replace tokens in encoded JSON

Rejected. The executed probe `json_encode([0, NAN, INF, -INF], JSON_PARTIAL_OUTPUT_ON_ERROR)` returned `[0,0,0,0]` with `JSON_ERROR_INF_OR_NAN`. The output no longer identifies which zeros were non-finite. Other encoding failures must not become partial successful payloads. Literal strings such as `"NaN"` must remain unchanged.

### 4. Narrow the repair domain and preserve native delegation

**Approved direction.** The current bounds and fallback policy are documented in [Event JSON encoding](json-serialization.md).

- Define an eligible, callback-free data domain rather than emulate arbitrary PHP object serialization.
- Detect eligibility without reading object properties, invoking callbacks or binding caller reference cells.
- Delegate an ineligible **whole payload** unchanged to one native encoding operation. Merely passing objects through while copying surrounding arrays is not enough: callbacks can retain or return ancestor arrays, so copying can change native identity/recursion behavior.
- Repair only eligible values, preserve object/list distinctions and numeric null positions, and leave caller values unchanged.
- If eligibility cannot be established within its limits, use unchanged native delegation rather than reject an otherwise healthy finite payload or allocate a fallback wrapper graph.
- Prove memory behavior for dense non-finite data as well as unchanged data. Native delegation alone does not prove that a normalization copy is cheap.
- Do not add a public option or another user hook. `Client::applyBeforeSend()` already supplies `before_send`; callers can explicitly provide JSON-safe data there. It is an escape hatch, not permission for the SDK to change native behavior silently.

This candidate does **not** automatically satisfy the existing object/non-finite feature tests. In particular, normalization of typed-object fields, stdClass fields, JsonSerializable enum/results, non-finite reference-cell updates, and mixed opaque/non-finite payloads must be explicitly retained or taken out of scope. Their examples must not be deleted or silently skipped to make a prototype green.

### Decision gate

The checkpoint required this decision before production implementation. The user subsequently chose the first option:

- Approve a precisely bounded data-only repair contract, including opaque/mixed-batch fallback and unsupported non-finite behavior; then prototype and test it.
- Require the full original object/reference normalization contract; then first establish a feasible native-compatible mechanism. If we cannot establish one, defer the feature rather than claim compatibility from another patch series.

No production change, commit, push or review-thread resolution was performed during the checkpoint itself. The later implementation follows the user's explicit approval rather than treating the narrowed feature expectations as incidental test fixes.

## Reported reproduction inventory

There are 18 top-level review comments representing 17 distinct concerns; the second Exception-hook comment duplicates the first. The mapping below concerns concrete reproductions, not proof that the whole defect family is solved.

`J` means `test/JsonEncoderTest.php`; `N` means `test/NonFinitePropertiesTest.php`; `R` means the new `test/JsonEncoderReviewRegressionTest.php`.

| Review comment ID | Reproduction and retained regression | Result on `577a8b7` at checkpoint |
|---|---|---|
| `4350801113`, `4350929352` | Internal-parent getter redaction and virtual properties: J `testPropertyHooksControlTheSerializedPublicView` / `testPropertyGetterExceptionsAreNotSwallowed`; N `testCapturePreservesSubclassPropertyHooks` | Existing cases pass |
| `4350801125` | Temporary serializer output retained across 100 x 1,000 objects: J `testTemporarySerializedObjectsFitWithinMemoryLimit` (64 MB) | Pass |
| `4350952993` | ArrayObject/ArrayIterator override dispatch: J `testNativeArrayFlagOverridesAreNotInvoked` and `testNativeStandardPropertyFlagsPreserveHooksDespiteOverride` | Pass |
| `4350953015` | Eager sibling wrappers: J `testSiblingContainersFitWithinNativeMemoryLimit`, including `empty arrays` (300,000; 32 MB) | Pass |
| `4356816316` | Earlier serializer redacts a later declared scalar: J `testDeclaredPropertyIsReadAfterEarlierSerializer`, `testHookedPropertyIsReadAfterEarlierSerializer` | Pass |
| `4356816321` | Shared 24-level invalid-UTF8 graph: J `testSharedInvalidArrayGraphIsRejectedWithoutEagerExpansion` (32 MB; two seconds) | Pass |
| `4357239411` | Earlier serializer unsets a later field, with/without magic getter: J `testDeclaredPropertyPresenceMatchesNativeEncoding`, `remove` cases | Pass |
| `4357265958` | Budget fallback creates a million wrappers: J `testLargePayloadEncodingMatchesNativeWithinResourceLimits`, `sibling allocation`; `json-encoder-scale.php` (2,000,000 arrays; 128 MB) | Pass |
| `4357701895` | Earlier serializer initializes a previously uninitialized field: J `testDeclaredPropertyPresenceMatchesNativeEncoding`, `initialize` cases | Pass |
| `4357701903` | Reference preparation copies the parent per element: J `testLargePayloadEncodingMatchesNativeWithinResourceLimits`, `reference traversal` (60,000 references; five seconds) | Pass |
| `4358008186` | Permanent tables on declared-only objects: J `testSiblingContainersFitWithinNativeMemoryLimit`, `declared-only objects` and neighboring non-finite case (100,000 objects; 32 MB) | Pass |
| `4358008192` | SimpleXML subclass native view: J `testSimpleXmlSubclassesKeepTheirNativePropertyView` | Pass |
| `4358008196` | A referenced slot points to a replacement array: J `testReplacedReferencedArrayIsNotTreatedAsTheActiveArray` (map/empty/nested); `testOuterReferenceRemainsActiveAfterTemporaryReplacement` | Pass |
| `4358414871` | Abstract virtual property becomes a backed slot before inherited redaction: R `testBackedImplementationOfAbstractPropertyPreservesNativeRedactionOrder` | **Fails:** SDK reads the unredacted value first |
| `4358414885` | Initial active array has no reference ID: R `testActiveArrayDoesNotReenterItsSerializer` (stateless/stateful) | **Both fail:** callback count is two; stateful case is accepted instead of rejected |
| `4358414893` | Hooked DateInterval gains extra fields and changes subsequent native output: R `testHookedDateIntervalPreservesNativeViewAndCallerState` | **Fails:** interval fields are added; the native before/after probe also shows caller mutation |
| `4358414903` | Full encoded string duplicated in the buffer: R `testLargeStringMatchesNativeWithin32MbBeforeBatchSizeRejection`; `json-encoder-large-string.php` (12 MiB ASCII; 32 MB) | **Fails:** native succeeds; SDK exits with memory exhaustion |

Review links use `https://github.com/PostHog/posthog-php/pull/276#discussion_r<comment-ID>`.

Additional pre-review reproductions are also retained: the paired recursive serializer chain added in `04d9f63`, native object formats, embedded-NUL property names and serializable enums (`25ad084`), stdClass-subclass hooks (`4059fad`), and Closure representation (`a3fd115`). Their cases remain in J/N and `test/NonFiniteJsonSerializableEnum.php`.

No existing tests or assertions were removed, weakened or skipped by this checkpoint. Historical RED evidence for earlier findings is recorded in the PR replies/history; this audit reran the current retained cases, not every historical pre-fix revision.

## Historical checkpoint validation

Environment: PHP 8.5.10, PHPUnit 11.5.55, local offline/loopback fixtures. No production code was changed. The new hook/abstract-property tests are gated below PHP 8.4. These new tests were not run locally on PHP 8.2/8.3/8.4 or with Xdebug; published-head CI results do not include them.

```sh
./vendor/bin/phpunit --bootstrap vendor/autoload.php --configuration phpunit.xml --no-coverage \
  test/JsonEncoderTest.php test/NonFinitePropertiesTest.php
# PASS: 113 tests, 862 assertions.

./vendor/bin/phpunit --bootstrap vendor/autoload.php --configuration phpunit.xml --no-coverage \
  test/JsonEncoderReviewRegressionTest.php
# EXPECTED RED: 5 tests, 20 assertions, 5 behavior/resource failures.
# All native-oracle/setup checks pass before the SDK failures.

./vendor/bin/phpunit --bootstrap vendor/autoload.php --configuration phpunit.xml --no-coverage
# RED: 1,548 tests, 8,011 assertions, exactly the five new failures.
# Existing notices: 27 deprecations and 42 PHPUnit deprecations.

./vendor/bin/phpcs --standard=phpcs.xml \
  test/JsonEncoderReviewRegressionTest.php test/fixtures/json-encoder-large-string.php
# PASS.

git diff --exit-code -- lib api composer.json phpunit.xml
# PASS: production, API snapshot, dependencies and runner configuration unchanged.
```

The large-string fixture compares independent native/SDK processes, frees the first encoded string before checking the batch guard, and keeps the existing 32 MB limit. Its failure is not caused by retaining two completed encodings in the test harness.

## Approved implementation and coverage changes

The custom writer, native-handler cases, property-layout reconstruction, active-reference maps and synthetic error restoration were removed. The encoder now has a read-only eligibility pass (10,000 values, 64 array levels, 1 MiB raw string/key bytes), a compact data normalizer with unchanged-subtree reuse, and one final native payload encoding. Opaque values, references, malformed strings and exhausted budgets cause unchanged native delegation, not a new encoding rejection. Primitive string validation uses `JSON_THROW_ON_ERROR` so it does not reset JSON error state seen by user callbacks.

No concrete reported reproduction was deleted or skipped. The five new finite/native-parity tests in `JsonEncoderReviewRegressionTest.php` pass without changing their expectations. The following feature expectations changed only because the user approved narrowing the normalization domain:

- The original mixed native-object/reference fixture in `testSupportedObjectRepresentationsAndReferencesArePreserved` remains and now asserts native failure for its non-finite mode; a finite counterpart preserves native format coverage.
- Non-finite reference slots, object fields and serializer output now assert native failure/error parity and caller identity/value preservation. The original stdClass/typed-object/serializer fixtures remain present.
- All 28 reference-mutation cases remain. Transitions to non-finite values assert native failure; transitions to finite values retain native wire output and callback counts.
- Hook privacy tests retain the original opaque/non-finite combinations as native-failure cases and add finite companions that still assert filtered wire values and getter counts.
- The large sibling/native-handler memory shapes remain under their original limits. Opaque or over-budget non-finite combinations compare native failure instead of pre-sanitized null data. Finite native-handler companions preserve successful-output coverage.
- The original six batch/File object-normalization integration fixtures remain in `testOpaqueNonFinitePropertiesKeepNativeFailureAndCallerState`. Six new data-only integration cases prove NaN/infinity repair, healthy-neighbor delivery and caller immutability in the supported domain.
- Serializable-enum and Closure examples remain. Opaque non-finite payloads assert native failure; finite Closure wire-format and non-invocation coverage is retained.

`JsonEncoderDataOnlyTest.php` defines the new policy independently: eligible numeric repair, whole-payload opaque/reference fallback, no serializer retry, exact value/byte/depth boundaries, successful finite fallback outside those bounds, and 32 MiB repair probes for dense arrays and escaped strings. The new policy was run against the previous writer before replacement and produced six expected failures; all five latest review reproductions were also red at the checkpoint.

The approved contract deliberately does not isolate mixed batches event by event, normalize values produced by arbitrary objects, or repair payloads beyond the eligibility bounds. These limitations are customer-visible in the encoding contract rather than hidden in test changes.

## Exit criteria for the replacement design

1. Approve the normalization domain and fallback policy in the PR before modifying production code or changing feature-test expectations.
2. Preserve every concrete reproduction above. Native-compatibility/privacy tests are not negotiable merely because a feature is narrowed.
3. Define the oracle for each category: native result/error/callback/state parity versus explicitly agreed non-finite-to-null transformation.
4. Validate the complete matrix on PHP 8.2-8.5, with applicable hook skips, Xdebug coverage and independent native resource baselines.
5. Retain batch/File integration coverage and demonstrate healthy-neighbor behavior for the explicitly supported domain. Do not assume isolated event encoding preserves all whole-batch callback semantics.
6. Review the design's state/ownership/resource invariants before requesting another automatic review. Another passing test count alone is not the exit criterion.

## First approved revision validation (`d16a8b1`)

Local validation of the approved data-only implementation on PHP 8.5.10:

- 159 focused tests / 1,183 assertions pass across `JsonEncoderDataOnlyTest`, `JsonEncoderReviewRegressionTest`, `JsonEncoderTest` and `NonFinitePropertiesTest`.
- The complete suite passes with Xdebug 3.5.3 coverage: 1,589 tests / 8,312 assertions. Existing notices remain 27 deprecations and 42 PHPUnit deprecations.
- All 16 adapter tests pass.
- Changed PHP files pass PHP_CodeSniffer; the public API snapshot is unchanged/up to date; strict Composer validation passes.
- The five newest native-parity regressions pass with their original expectations. No reported reproduction was deleted or skipped to obtain this result.
- New 32 MiB probes compare eligible dense non-finite arrays and escaped strings with independently constructed native-null payloads. Oversized finite strings and finite payloads outside each repair budget retain native success.

The design invariant review checks that copying happens only after full eligibility, that no eligible graph can run a user serializer/getter or contain reference cells, that unchanged and ineligible payloads use their original native graph, and that string validation cannot overwrite the JSON error state seen by native callbacks. The initial allocation review incorrectly assumed that copy-on-write storage was bounded by live entries; the correction below records the counterexample and removes that assumption. Budget exhaustion still falls back rather than inventing an encoding error.

PHP 8.2-8.5 CI is required as the cross-version acceptance gate; local results alone are not a matrix-wide validation.

## Retained-capacity correction

Two subsequent reviews reported the same allocation defect: [4359164748](https://github.com/PostHog/posthog-php/pull/276#discussion_r4359164748) and [4359218233](https://github.com/PostHog/posthog-php/pull/276#discussion_r4359218233). A million-slot array pruned to one live non-finite value passes the value budget, but PHP copy-on-write duplicates its retained 16 MiB backing allocation. The repeated-child example amplifies the same defect. This invalidated the first revision's allocation argument without changing the approved eligibility/serialization contract.

Three retained-capacity tests were red on `d16a8b1`: the reported million-slot root, 16 repeated pruned children, and a sparse high live key. Under the same 32 MiB limit, each native non-finite baseline returned its normal error and each equivalent native-null baseline encoded successfully; SDK encoding fatally attempted an extra 16 MiB allocation. The tests and fixture remain in `JsonEncoderDataOnlyTest.php` and `test/fixtures/json-encoder-retained-capacity.php`.

Normalization now compacts live entries with key-preserving `array_slice` before mapping values. It never writes into a copy seeded from the original table. Compaction must precede mapping: `array_map` alone can retain a high packed index extent. Mapping also preserves genuine numeric-string keys from symbol tables; ordinary PHP keyed assignment coerces them to integers and can change an object-shaped JSON value into a list. A separate red/green test protects this behavior, and a prefix/sparse-key test protects unchanged values and insertion order.

The revised allocation invariant concerns newly allocated storage, not the caller's existing capacity: candidate tables are constructed from live entries, unchanged subtrees are reused without writes, and each repeated changed occurrence receives compact storage. Budgets cap recursive value visits and string validation. PHP iteration over holes can still reflect existing backing extent, so live-entry counts alone are not a bound on every internal scan.

Local validation of the correction on PHP 8.5.10: 164 focused tests / 1,216 assertions; 1,594 full tests / 8,345 assertions with Xdebug 3.5.3 coverage; 16 adapter tests; changed-file PHP_CodeSniffer, API snapshot, strict Composer and whitespace checks pass. The original five latest native-parity regressions remain unchanged and green. Existing deprecation counts remain 27 and 42. The PHP 8.2-8.5 matrix must also pass before thread resolution.
