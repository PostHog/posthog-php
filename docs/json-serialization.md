# Event JSON encoding

NaN and positive/negative infinity are encoded as JSON `null` when the **entire payload** is an eligible data-only tree. Array positions and keys, finite values, literal strings such as `"NaN"`, and caller-owned values are preserved.

An eligible payload contains only PHP arrays, strings, numbers, booleans and null, with no objects, resources or retained array-element references. Repair is bounded to:

| Limit | Scope |
|---|---|
| 10,000 values | Includes the root, each container and each array value |
| 64 array levels | The complete payload, not just custom properties |
| 1 MiB of string bytes | Combined raw bytes of string values and string keys, before JSON escaping |

The inspection is read-only and invokes no getters or serializers. Repair starts only after the entire payload qualifies. It builds compact candidate arrays from live entries, preserving key types and order, and reuses unchanged subtrees. It never seeds a writable copy from the caller's array, whose retained backing capacity can greatly exceed its live entry count. Individual strings are validated without resetting the JSON error state seen by callbacks.

## Native fallback

If any value is opaque or unsupported, a reference is found, a string is malformed, or a bound is exceeded, the **original whole payload** is passed unchanged to native `json_encode()`. It is not partially repaired and is not retried. Finite payloads outside the repair bounds are still encoded normally.

Objects include stdClass, enums, Closure, JsonSerializable implementations and native extension objects. The SDK does not inspect their properties or reproduce their serialization handlers. Native property ordering, callback counts, redaction, recursion detection, user exceptions and JSON errors remain authoritative.

Consequently, non-finite numbers in an ineligible payload still cause the native encoding failure. This includes numbers inside objects or references, values produced by a serializer, and plain non-finite fields next to an opaque value. Capture callers needing a different policy can supply JSON-safe data through the existing `before_send` hook; no new SDK option is introduced.

## Batch and File behavior

For queued consumers, the payload includes the entire batch, API key and SDK metadata. One opaque value or reference in any event disables repair for the whole batch. A mixed batch containing an object and a non-finite number can therefore still fail native encoding, including its healthy neighboring events. This is not per-event error isolation.

For the File consumer, eligibility applies to each complete event. Ineligible events retain the File consumer's existing native-failure handling, including its empty-line output when encoding fails; this change does not replace that handling.

The existing 1 MiB batch **encoded body** limit is separate from the raw-string repair budget. Escaping can enlarge JSON. Oversized valid batches are still rejected by the existing size guard after encoding, and large finite strings use native encoding without a second SDK output buffer.

No partial-output flags, string-token replacement, object casting, reflection-based property traversal, reference binding or serializer retries are used.

The design history and complete review-reproduction inventory are recorded in [the design checkpoint](json-serialization-design-review.md).
