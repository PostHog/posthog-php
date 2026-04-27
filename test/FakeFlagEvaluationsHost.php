<?php

namespace PostHog\Test;

use PostHog\FeatureFlagEvaluationsHost;

/**
 * In-memory FeatureFlagEvaluationsHost implementation used by FeatureFlagEvaluationsTest. Records
 * each interaction so tests can assert on it without spinning up a real Client.
 */
class FakeFlagEvaluationsHost implements FeatureFlagEvaluationsHost
{
    /** @var array<int, array<string, mixed>> */
    public array $captures = [];
    /** @var list<string> */
    public array $warnings = [];
    /** @var array<string, true> */
    private array $seen = [];

    public function captureFlagCalledIfNeeded(
        string $distinctId,
        string $key,
        array $properties,
        array $groups
    ): void {
        $cacheKey = $distinctId . "\0" . $key;
        if (isset($this->seen[$cacheKey])) {
            return;
        }
        $this->seen[$cacheKey] = true;
        $this->captures[] = [
            'distinct_id' => $distinctId,
            'key' => $key,
            'properties' => $properties,
            'groups' => $groups,
        ];
    }

    public function logWarning(string $message): void
    {
        $this->warnings[] = $message;
    }
}
