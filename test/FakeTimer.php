<?php

namespace PostHog\Test;

use RuntimeException;

/**
 * The monotonic timer and the sleeps that symfony_clock_time_mock.php gives Symfony's clocks. The wall clock keeps
 * real time, so next to this timer it looks stepped back by everything slept.
 */
final class FakeTimer
{
    private const MAX_SLEEPS = 50;

    public static int $nowUs = 0;
    /** A signal cuts the first sleep short after this long. */
    public static ?int $interruptFirstSleepAfterUs = null;
    private static int $sleeps = 0;

    public static function hrtime(bool $asNumber): array|int|float
    {
        // Split from microseconds: nanoseconds pass PHP_INT_MAX after about 2 s on 32-bit PHP.
        $seconds = intdiv(self::$nowUs, 1_000_000);
        $ns = self::$nowUs % 1_000_000 * 1000;

        // Like the real hrtime(true), the number is a float on 32-bit PHP.
        return $asNumber ? $seconds * 1_000_000_000 + $ns : [$seconds, $ns];
    }

    public static function sleep(int $us): void
    {
        if (++self::$sleeps > self::MAX_SLEEPS) {
            throw new RuntimeException('The retry wait never ended.');
        }
        if (self::$sleeps === 1 && self::$interruptFirstSleepAfterUs !== null) {
            $us = min($us, self::$interruptFirstSleepAfterUs);
        }
        self::$nowUs += $us;
    }
}
