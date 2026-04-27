<?php

namespace PostHog;

/**
 * Narrow callback surface that FeatureFlagEvaluations uses to interact with its owning Client.
 *
 * Splitting the interface out keeps the snapshot easy to unit-test with a fake host instead of a
 * full Client.
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
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $groups
     */
    public function captureFlagCalledIfNeeded(
        string $distinctId,
        string $key,
        array $properties,
        array $groups
    ): void;

    /**
     * Emit a non-fatal warning. Implementations may suppress these when feature_flags_log_warnings
     * is disabled in the client configuration.
     */
    public function logWarning(string $message): void;
}
