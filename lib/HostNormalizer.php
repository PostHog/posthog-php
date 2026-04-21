<?php

namespace PostHog;

class HostNormalizer
{
    public const DEFAULT_HOST = 'us.i.posthog.com';

    public static function normalize(?string $host): string
    {
        $normalized = trim((string) $host);
        return $normalized === '' ? self::DEFAULT_HOST : $normalized;
    }
}
