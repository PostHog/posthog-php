<?php

namespace PostHog\Laravel;

use PostHog\StringNormalizer;

/**
 * @internal Reads the project token from the `token` config key. Configs published before that
 * key existed name it `api_key`, so that key still works when `token` is blank.
 */
final class ProjectToken
{
    public static function fromConfig(array $config): ?string
    {
        return StringNormalizer::normalizeOptional(self::stringOrNull($config['token'] ?? null))
            ?? StringNormalizer::normalizeOptional(self::stringOrNull($config['api_key'] ?? null));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
