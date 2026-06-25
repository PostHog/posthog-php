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

    /**
     * Generate a UUID v7 string using the RFC 9562 Unix timestamp layout.
     *
     * PHP does not provide a built-in UUID API in the SDK's supported runtimes, so
     * this internal helper keeps UUID v7 generation local without adding a runtime dependency.
     *
     * @return string UUID v7.
     * @throws \Random\RandomException When random_bytes() cannot gather sufficient entropy.
     */
    public static function v7(): string
    {
        $bytes = random_bytes(16);
        $timestamp = (int) floor(microtime(true) * 1000);

        for ($i = 5; $i >= 0; --$i) {
            $bytes[$i] = chr($timestamp & 0xff);
            $timestamp >>= 8;
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
