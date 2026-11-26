<?php

namespace PostHog\Test;

use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PostHog\Emails;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmailsOnOlderSymfonyClocksTest extends TestCase
{
    public static function olderClocks(): array
    {
        // symfony/clock 6.2 has no Clock class. Up to 6.3.0, loading NativeClock on PHP 8.4+ raises a deprecation,
        // which an app that turns deprecations into exceptions throws.
        return [
            'symfony/clock 6.2' => [true],
            'symfony/clock 6.3.0' => [false],
            'symfony/clock 6.2.0 on PHP 8.4, whose MonotonicClock raises a deprecation' => [true, true],
        ];
    }

    #[DataProvider('olderClocks')]
    public function testAPastTimeQueuedNowIsAnAcceptance(bool $withoutTheClockClass, bool $monotonicFails = false): void
    {
        self::loadWithAnOlderClock($withoutTheClockClass, $monotonicFails);
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

    #[DataProvider('olderClocks')]
    public function testABatchRetriesAfterALostAnswer(bool $withoutTheClockClass, bool $monotonicFails = false): void
    {
        self::loadWithAnOlderClock($withoutTheClockClass, $monotonicFails);
        $requests = 0;
        $emails = new Emails('phs_secret', 'us.i.posthog.com', transport: static function () use (&$requests): array {
            $requests++;

            return $requests === 1 ? [503, '', []] : [200, json_encode(['results' => [
                ['message_id' => 'm0', 'status' => 'queued'],
                ['message_id' => 'm1', 'status' => 'queued'],
            ]]), []];
        });

        $result = $emails->batch([
            'recipients' => [['to' => 'a@example.com'], ['to' => 'b@example.com']],
            'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b',
            'max_retries' => 1,
        ]);

        $this->assertSame(
            ['queued', 'queued'],
            array_map(static fn($r): string => $r->getStatus(), $result->getResults())
        );
        $this->assertSame(2, $requests);
    }

    private static function loadWithAnOlderClock(bool $withoutTheClockClass, bool $monotonicFails): void
    {
        foreach (spl_autoload_functions() as $loader) {
            spl_autoload_unregister($loader);
            $deprecated = ['Symfony\Component\Clock\NativeClock'];
            if ($monotonicFails) {
                $deprecated[] = 'Symfony\Component\Clock\MonotonicClock';
            }
            $without = $withoutTheClockClass;
            spl_autoload_register(static function (string $class) use ($loader, $without, $deprecated): void {
                if (in_array($class, $deprecated, true)) {
                    throw new ErrorException('Implicitly marking parameter $timezone as nullable is deprecated');
                }
                if (!$without || $class !== 'Symfony\Component\Clock\Clock') {
                    $loader($class);
                }
            });
        }
    }
}
