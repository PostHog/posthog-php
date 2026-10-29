<?php

namespace PostHog\Internal;

use PostHog\Client;
use PostHog\FeatureFlagEvaluationsHost;
use PostHog\RequestContext;

/** @internal */
final class ContextualFeatureFlagEvaluationsHost implements FeatureFlagEvaluationsHost
{
    public function __construct(
        private readonly Client $client,
        private readonly array $context,
        private readonly int $contextKey,
    ) {
    }

    public function captureFlagCalledIfNeeded(
        string $distinctId,
        string $key,
        $response,
        array $properties,
        array $groups,
        ?bool $disableGeoip = null
    ): void {
        // Restore event context without enriching an unrelated span active at the time of the read.
        RequestContext::withContext(
            $this->context,
            fn () => $this->client->captureFlagCalledIfNeeded(
                $distinctId,
                $key,
                $response,
                $properties,
                $groups,
                $disableGeoip,
            ),
            ['fresh' => true],
            $this->contextKey,
        );
    }

    public function logWarning(string $message): void
    {
        $this->client->logWarning($message);
    }
}
