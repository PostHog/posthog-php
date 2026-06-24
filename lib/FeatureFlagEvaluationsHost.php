<?php

namespace PostHog;

/**
 * Narrow callback surface that FeatureFlagEvaluations uses to interact with its owning Client.
 *
 * Splitting the interface out keeps the snapshot easy to unit-test with a fake host instead of a
 * full Client.
 *
 * @internal
 */
interface FeatureFlagEvaluationsHost
{
    /**
     * Fire a $feature_flag_called event for ($distinctId, $key) unless it has already been recorded
     * for this distinct id within the dedup window.
     *
     * The properties array is built by the caller so this helper does not need to reconstruct the
     * shape from raw response data.
     *
     * @param string $distinctId The distinct ID that accessed the flag.
     * @param string $key Feature flag key.
     * @param mixed $response Evaluated feature flag response for deduping.
     * @param array<string, mixed> $properties Event properties for the $feature_flag_called event.
     * @param array<string, mixed> $groups Group identifiers for group-based flags.
     * @return void
     */
    public function captureFlagCalledIfNeeded(
        string $distinctId,
        string $key,
        $response,
        array $properties,
        array $groups
    ): void;

    /**
     * Emit a non-fatal warning.
     *
     * @param string $message Warning message.
     * @return void
     */
    public function logWarning(string $message): void;
}
