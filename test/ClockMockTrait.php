<?php

namespace PostHog\Test;

use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * Trait providing time mocking functionality for tests using Symfony Clock.
 * This replaces the slope-it/clock-mock dependency which required the uopz extension.
 */
trait ClockMockTrait
{
    /**
     * Execute a callback with a frozen date/time.
     * This mimics the behavior of SlopeIt\ClockMock\ClockMock::executeAtFrozenDateTime()
     *
     * @param \DateTimeInterface $dateTime The date/time to freeze to
     * @param callable $callback The callback to execute
     * @return mixed The return value of the callback
     */
    protected static function executeAtFrozenDateTime(\DateTimeInterface $dateTime, callable $callback): mixed
    {
        $mockClock = new MockClock($dateTime instanceof \DateTimeImmutable
            ? $dateTime
            : \DateTimeImmutable::createFromInterface($dateTime));

        Clock::set($mockClock);

        try {
            return $callback();
        } finally {
            // Reset to real clock
            Clock::set(new \Symfony\Component\Clock\NativeClock());
        }
    }
}
