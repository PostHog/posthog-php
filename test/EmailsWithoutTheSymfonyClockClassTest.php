<?php

namespace PostHog\Test;

use Error;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PostHog\EmailError;
use PostHog\Emails;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmailsWithoutTheSymfonyClockClassTest extends TestCase
{
    public function testAQueuedAnswerOnSymfonyClock62StillNamesTheAcceptedEmail(): void
    {
        // symfony/clock 6.2 has no Clock class; refuse to load it, as Composer would on 6.2.
        spl_autoload_register(static function (string $class): void {
            if ($class === 'Symfony\Component\Clock\Clock') {
                throw new Error("Class \"$class\" not found");
            }
        }, true, true);
        $messageId = '01a18356-c56c-0001-e494-7666bc915188';
        $emails = new Emails('phs_secret', 'us.i.posthog.com', transport: static fn(): array => [
            202,
            json_encode(['message_id' => $messageId, 'status' => 'queued', 'scheduled_at' => null]),
            [],
        ]);

        try {
            $emails->send([
                'to' => 'user@example.com',
                'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b',
                'scheduled_at' => '2026-11-01T09:00:00Z',
            ]);
            $this->fail('No EmailError was thrown.');
        } catch (EmailError $error) {
            $this->assertSame('schedule_not_confirmed', $error->getErrorCode());
            $this->assertSame($messageId, $error->getMessageId());
        }
    }
}
