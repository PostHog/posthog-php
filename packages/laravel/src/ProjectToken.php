<?php

namespace PostHog\Laravel;

use PostHog\StringNormalizer;

/**
 * @internal Reads the project token from the `project_token` config key, then from the deprecated
 * `api_key` key when `project_token` is blank.
 */
final class ProjectToken
{
    public static function fromConfig(array $config): ?string
    {
        return StringNormalizer::normalizeOptional(self::stringOrNull($config['project_token'] ?? null))
            ?? StringNormalizer::normalizeOptional(self::stringOrNull($config['api_key'] ?? null));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
