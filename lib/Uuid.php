<?php

namespace PostHog;

/**
 * UUID generation helpers.
 *
 * @internal
 */
final class Uuid
{
    /**
     * Generate a random UUID v4 string.
     *
     * @return string UUID v4.
     * @throws \Random\RandomException When random_int() cannot gather sufficient entropy.
     */
    public static function v4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
