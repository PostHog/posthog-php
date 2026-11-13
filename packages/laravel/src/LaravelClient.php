<?php

namespace PostHog\Laravel;

use PostHog\Client;
use PostHog\Laravel\Delivery\QueuedConsumer;

/** @internal Reports Laravel library metadata and the cached-config release id. */
final class LaravelClient extends Client
{
    private readonly ?string $configuredReleaseId;

    public function __construct(
        ?string $apiKey,
        array $options,
        ?string $releaseId,
        ?string $secretKey = null,
        ?QueuedConsumer $queuedConsumer = null,
    ) {
        $this->configuredReleaseId = $releaseId;
        if ($queuedConsumer !== null) {
            $options['http_timeout_ms'] ??= ($options['consumer'] ?? null) === 'socket'
                ? 10000 : ($options['timeout'] ?? 10000);
            $options['consumer'] = 'noop';
        }
        parent::__construct($apiKey, $options, loadFeatureFlags: false, secretKey: $secretKey);
        if ($queuedConsumer !== null) {
            $this->consumer = $queuedConsumer;
        }
    }

    protected function libraryName(): string
    {
        return 'posthog-laravel';
    }

    protected function libraryVersion(): string
    {
        return PostHog::VERSION;
    }

    protected function releaseId(): ?string
    {
        return $this->configuredReleaseId ?? parent::releaseId();
    }
}
