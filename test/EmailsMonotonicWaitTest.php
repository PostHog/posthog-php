<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PostHog\Emails;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmailsMonotonicWaitTest extends TestCase
{
    public function testTheRetryWaitFollowsTheMonotonicTimerWhenTheWallClockIsStepped(): void
    {
        require_once __DIR__ . '/symfony_clock_time_mock.php';
        Clock::set(new NativeClock());
        FakeTimer::$interruptFirstSleepAfterUs = 1_000_000;
        $replies = [[429, '{"code":"throttled"}', ['Retry-After: 3']], [202, '{"message_id":"msg_1"}', []]];
        $sentAtUs = [];
        $emails = new Emails('phs_secret', 'us.i.posthog.com', transport: function () use (&$replies, &$sentAtUs) {
            $sentAtUs[] = FakeTimer::$nowUs;
            return array_shift($replies);
        });

        $result = $emails->send([
            'to' => 'user@example.com',
            'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b',
        ]);

        $this->assertSame('msg_1', $result->getId());
        $this->assertSame([0, 3_000_000], $sentAtUs);
    }
}
