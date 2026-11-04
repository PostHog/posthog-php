<?php

namespace PostHog\Test;

use Closure;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Clock\MockClock;

final class InterruptedClock implements ClockInterface
{
    private bool $interrupted = false;

    public function __construct(
        private readonly MockClock $clock,
        private readonly float $interruptAfterSeconds,
        private readonly Closure $handler
    ) {
    }

    public function now(): DatePoint
    {
        return $this->clock->now();
    }

    public function sleep(float|int $seconds): void
    {
        if ($this->interrupted || $seconds <= $this->interruptAfterSeconds) {
            $this->clock->sleep($seconds);
            return;
        }
        $this->interrupted = true;
        $this->clock->sleep($this->interruptAfterSeconds);
        ($this->handler)();
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        return new self($this->clock->withTimeZone($timezone), $this->interruptAfterSeconds, $this->handler);
    }
}
