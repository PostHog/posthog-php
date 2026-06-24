<?php

namespace PostHog\Test;

use PostHog\FeatureFlagEvaluationsHost;

/**
 * In-memory FeatureFlagEvaluationsHost implementation used by FeatureFlagEvaluationsTest. Records
 * each interaction so tests can assert on it without spinning up a real Client.
 */
class FakeFlagEvaluationsHost implements FeatureFlagEvaluationsHost
{
    /** @var list<string> */
    public array $warnings = [];

    public function logWarning(string $message): void
    {
        $this->warnings[] = $message;
    }
}
