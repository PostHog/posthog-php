<?php

namespace PostHog\Laravel;

use PostHog\Client;

/** @internal Reports Laravel library metadata and the cached-config release id. */
final class LaravelClient extends Client
{
    public function __construct(
        ?string $apiKey,
        array $options,
        private readonly ?string $configuredReleaseId,
        ?string $secretKey = null,
    ) {
        parent::__construct($apiKey, $options, loadFeatureFlags: false, secretKey: $secretKey);
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
