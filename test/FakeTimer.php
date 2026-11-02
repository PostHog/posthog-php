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

    public static function hrtime(bool $asNumber): array|int
    {
        $ns = self::$nowUs * 1000;

        return $asNumber ? $ns : [intdiv($ns, 1_000_000_000), $ns % 1_000_000_000];
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
