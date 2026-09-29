<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

class ClockMockTraitTest extends TestCase
{
    use ClockMockTrait;

    public function testNestedFrozenClocksRestoreTheirCallerEvenOnException(): void
    {
        $original = Clock::get();
        $outer = new MockClock('2024-01-01T00:00:00+00:00');
        Clock::set($outer);
        try {
            $value = $this->executeAtFrozenDateTime(new \DateTimeImmutable('2025-01-01 UTC'), function (): string {
                $inner = Clock::get();
                try {
                    $this->executeAtFrozenDateTime(new \DateTimeImmutable('2026-01-01 UTC'), function (): void {
                        self::assertSame('2026-01-01', Clock::get()->now()->format('Y-m-d'));
                        throw new RuntimeException('callback failed');
                    });
                    self::fail('Expected callback exception');
                } catch (RuntimeException $exception) {
                    self::assertSame('callback failed', $exception->getMessage());
                }
                self::assertSame($inner, Clock::get());
                return Clock::get()->now()->format('Y-m-d');
            });
            self::assertSame('2025-01-01', $value);
            self::assertSame($outer, Clock::get());
        } finally {
            Clock::set($original);
        }
    }
}
