<?php

namespace PostHog;

class StringNormalizer
{
    public const DEFAULT_HOST = 'us.i.posthog.com';

    public static function normalizeOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        return $normalized === '' ? null : $normalized;
    }

    public static function normalizeHost(?string $host): string
    {
        return self::normalizeOptional($host) ?? self::DEFAULT_HOST;
    }
}
