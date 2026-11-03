<?php

// phpcs:disable PSR1.Files.SideEffects
// Gives Symfony's clocks the FakeTimer's monotonic timer and sleeps. Load it only in a separate process, before any
// Symfony clock sleeps or reads hrtime().
namespace Symfony\Component\Clock;

use PostHog\Test\FakeTimer;

function hrtime(bool $asNumber = false): array|int|float
{
    return FakeTimer::hrtime($asNumber);
}

function sleep(int $seconds): int
{
    FakeTimer::sleep($seconds * 1_000_000);

    return 0;
}

function usleep(int $microseconds): void
{
    FakeTimer::sleep($microseconds);
}
