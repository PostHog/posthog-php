<?php

namespace PostHog\Test;

use PostHog\Client;

/**
 * Mirrors how a PostHog framework integration overrides library metadata and release id.
 */
class IntegrationClient extends Client
{
    public static string $name = 'posthog-laravel';
    public static string $version = '0.1.0';
    public static ?string $release = null;

    protected function libraryName(): string
    {
        return self::$name;
    }

    protected function libraryVersion(): string
    {
        return self::$version;
    }

    protected function releaseId(): ?string
    {
        return self::$release ?? parent::releaseId();
    }

    public static function reset(): void
    {
        self::$name = 'posthog-laravel';
        self::$version = '0.1.0';
        self::$release = null;
    }
}
