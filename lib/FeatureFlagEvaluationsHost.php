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
     * Emit a non-fatal warning.
     *
     * @param string $message Warning message.
     * @return void
     */
    public function logWarning(string $message): void;
}
