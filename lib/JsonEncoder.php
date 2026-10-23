<?php

namespace PostHog;

use JsonException;
use ReflectionReference;

/**
 * Repairs non-finite numbers in bounded, object/reference-free data payloads.
 *
 * @internal
 */
final class JsonEncoder
{
    private const MAX_DEPTH = 64;
    private int $remainingValues = 10000;
    private int $remainingBytes = 1048576;
    private bool $hasNonFinite = false;

    private function __construct()
    {
    }

    public static function encode(mixed $value): string|false
    {
        // Opaque callbacks may retain ancestor arrays, so eligibility must precede every copy.
        $inspection = new self();
        if ($inspection->eligible($value, 0) && $inspection->hasNonFinite) {
            $changed = false;
            $value = self::normalize($value, $changed);
        }
        return json_encode($value);
    }

    private function eligible(mixed $value, int $depth): bool
    {
        if (--$this->remainingValues < 0) {
            return false;
        }
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return false;
            }
            foreach ($value as $key => $item) {
                if (
                    ReflectionReference::fromArrayElement($value, $key) !== null
                    || (is_string($key) && !$this->eligibleString($key))
                    || !$this->eligible($item, $depth + 1)
                ) {
                    return false;
                }
            }
            return true;
        }
        if (is_string($value)) {
            return $this->eligibleString($value);
        }
        if (is_float($value) && !is_finite($value)) {
            $this->hasNonFinite = true;
        }
        return $value === null || is_scalar($value);
    }

    private function eligibleString(string $value): bool
    {
        $this->remainingBytes -= strlen($value);
        if ($this->remainingBytes < 0) {
            return false;
        }
        // Validation must not reset the JSON error state visible to subsequent native callbacks.
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
            return true;
        } catch (JsonException) {
            return false;
        }
    }

    private static function normalize(mixed $value, bool &$changed): mixed
    {
        $changed = is_float($value) && !is_finite($value);
        if ($changed) {
            return null;
        }
        if (!is_array($value)) {
            return $value;
        }
        // Compact retained storage first; array_map also preserves numeric-string keys without PHP key coercion.
        $compact = array_slice($value, 0, null, true);
        $result = array_map(static function (mixed $item) use (&$changed): mixed {
            $childChanged = false;
            $normalized = self::normalize($item, $childChanged);
            $changed = $changed || $childChanged;
            return $normalized;
        }, $compact);
        return $changed ? $result : $value;
    }
}
