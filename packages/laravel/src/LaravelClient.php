<?php

namespace PostHog\Laravel;

use PostHog\Client;

/** @internal Reports Laravel library metadata and the cached-config release id. */
final class LaravelClient extends Client
{
    private readonly ?string $configuredReleaseId;

    public function __construct(
        ?string $apiKey,
        array $options,
        ?string $releaseId,
        ?string $secretKey = null,
    ) {
        $this->configuredReleaseId = $releaseId;
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
