<?php

namespace PostHog\Test;

use RuntimeException;

final class FakeTimer
{
    private const MAX_SLEEPS = 50;

    public static int $nowUs = 0;
    public static ?int $interruptFirstSleepAfterUs = null;
    private static int $sleeps = 0;

    public static function hrtime(bool $asNumber): array|int|float
    {
        $seconds = intdiv(self::$nowUs, 1_000_000);
        $ns = self::$nowUs % 1_000_000 * 1000;

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
