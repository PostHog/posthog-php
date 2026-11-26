<?php

namespace PostHog\Test;

use Closure;
use DateTimeImmutable;
use PostHog\Emails;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Builds Emails on the test's `transport()` method, which records each request in `$requests` and answers
 * from `$replies`, and runs the test on a mock clock.
 */
trait EmailsTestSupport
{
    /**
     * @var list<array{method: string, url: string, headers: list<string>, body: ?string, timeoutMs: int,
     *     at: DateTimeImmutable}>
     */
    private array $requests = [];
    /** @var list<mixed> */
    private array $replies = [];
    private string $secretKey = 'phs_secret';
    private ClockInterface $previousClock;
    private MockClock $clock;

    protected function setUp(): void
    {
        require_once __DIR__ . '/error_log_mock.php';
        global $errorMessages;
        $errorMessages = [];
        $this->previousClock = Clock::get();
        $this->clock = new MockClock('2026-11-02 12:00:00');
        Clock::set($this->clock);
    }

    protected function tearDown(): void
    {
        Clock::set($this->previousClock);
    }

    private function emails(array $config = []): Emails
    {
        $this->secretKey = $config['secretKey'] ?? 'phs_secret';

        return new Emails(
            $this->secretKey,
            $config['host'] ?? 'us.i.posthog.com',
            appHost: $config['appHost'] ?? null,
            requestTimeoutMs: $config['requestTimeoutMs'] ?? Emails::DEFAULT_REQUEST_TIMEOUT_MS,
            maxRetries: $config['maxRetries'] ?? Emails::DEFAULT_MAX_RETRIES,
            migratedFrom: $config['migratedFrom'] ?? null,
            userAgent: 'posthog-php/9.9.9',
            isDisabled: $config['isDisabled'] ?? null,
            isClosed: $config['isClosed'] ?? null,
            transport: Closure::fromCallable([$this, 'transport'])
        );
    }

    private function reply(mixed ...$replies): void
    {
        $this->replies = $replies;
    }

    private function body(int $index = 0): array
    {
        return json_decode($this->requests[$index]['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function header(string $name, int $index = 0): ?string
    {
        return self::headerIn($this->requests[$index]['headers'], $name);
    }

    /** @param list<string> $headers */
    private static function headerIn(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    private static function msBetween(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int) round(((float) $end->format('U.u') - (float) $start->format('U.u')) * 1000);
    }
}
