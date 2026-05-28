<?php

namespace PostHog;

/**
 * Normalizes optional strings and host defaults.
 *
 * @internal
 */
class StringNormalizer
{
    public const DEFAULT_HOST = 'us.i.posthog.com';

    /**
     * Trim an optional string and convert empty strings to null.
     *
     * @param string|null $value Value to normalize.
     * @return string|null Normalized string or null.
     */
    public static function normalizeOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        return $normalized === '' ? null : $normalized;
    }

    /**
     * Normalize a host string, falling back to the SDK default host.
     *
     * @param string|null $host Host to normalize.
     * @return string Non-empty host.
     */
    public static function normalizeHost(?string $host): string
    {
        return self::normalizeOptional($host) ?? self::DEFAULT_HOST;
    }
}
