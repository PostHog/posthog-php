<?php

namespace PostHog\Test;

use ErrorException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PostHog\Emails;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmailsWithoutTheSymfonyClockClassTest extends TestCase
{
    public function testAPastTimeQueuedNowOnSymfonyClock62IsAnAcceptance(): void
    {
        // symfony/clock 6.2 has no Clock class, and on PHP 8.4+ loading its NativeClock raises a deprecation, which
        // an app that turns deprecations into exceptions throws. The wrapped loader does both.
        foreach (spl_autoload_functions() as $loader) {
            spl_autoload_unregister($loader);
            spl_autoload_register(static function (string $class) use ($loader): void {
                if ($class === 'Symfony\Component\Clock\NativeClock') {
                    throw new ErrorException('Implicitly marking parameter $timezone as nullable is deprecated');
                }
                if ($class !== 'Symfony\Component\Clock\Clock') {
                    $loader($class);
                }
            });
        }
        $this->assertFalse(class_exists('Symfony\Component\Clock\Clock'));
        $messageId = '01a18356-c56c-0001-e494-7666bc915188';
        $emails = new Emails('phs_secret', 'us.i.posthog.com', transport: static fn(): array => [
            202,
            json_encode(['message_id' => $messageId, 'status' => 'queued', 'scheduled_at' => null]),
            [],
        ]);

        $result = $emails->send([
            'to' => 'user@example.com',
            'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b',
            'scheduled_at' => '2020-01-01T09:00:00Z',
        ]);

        $this->assertSame($messageId, $result->getId());
        $this->assertSame('queued', $result->getStatus());
    }
}
