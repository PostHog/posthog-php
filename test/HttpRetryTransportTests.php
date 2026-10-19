<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

trait HttpRetryTransportTests
{
    public static function httpRetryCases(): array
    {
        return [
            'request timeout' => [408, [], true, 2, 100],
            'server error' => [500, [], true, 2, 100],
            'bad gateway' => [502, [], true, 2, 100],
            'unavailable' => [503, [], true, 2, 100],
            'gateway timeout' => [504, [], true, 2, 100],
            'rate limit' => [429, [], true, 2, 100],
            'retry after seconds' => [429, ['rEtRy-AfTeR' => '2'], true, 2, 200],
            'retry after zero' => [503, ['Retry-After' => '0'], true, 2, 0],
            'invalid retry after' => [503, ['Retry-After' => 'not-a-date'], true, 2, 100],
            'past retry after' => [503, ['Retry-After' => 'Sat, 01 Jan 2000 00:00:00 GMT'], true, 2, 0],
            'retry after beyond integer range' => [429, ['Retry-After' => '999999999999999999999999'], true, 2, 200],
            'long response headers' => [
                429, ['X-Padding' => str_repeat('a', 3000), 'Retry-After' => '2'], true, 2, 200,
            ],
            'gzip retry' => [503, [], true, 2, 100, true],
            'bounded retries' => [503, [], false, 2, 100],
            'bad request' => [400, [], false, 1, 0],
            'unauthorized' => [401, [], false, 1, 0],
            'forbidden' => [403, [], false, 1, 0],
            'too large' => [413, [], false, 1, 0],
        ];
    }

    #[DataProvider('httpRetryCases')]
    public function testHttpDeliveryRetryPolicy(
        int $status,
        array $headers,
        bool $success,
        int $attempts,
        int $delayMilliseconds,
        bool $compressed = false
    ): void {
        $this->server->stop();
        $responses = array_fill(0, $attempts - 1, ['status' => $status, 'headers' => $headers]);
        $responses[] = ['status' => $success ? 200 : $status, 'headers' => $headers];
        $this->server = new LocalHttpServer($responses);
        $client = $this->client(['maximum_backoff_duration' => 200, 'compress_request' => $compressed]);
        $clock = new MockClock();
        $started = $clock->now();
        $previousClock = Clock::get();
        Clock::set($clock);

        try {
            self::assertTrue($client->capture(['distinctId' => 'some-user', 'event' => 'retried event']));
            self::assertSame($success, $client->flush());
            $requests = $this->server->requests();
            self::assertCount($attempts, $requests);
            foreach ($requests as $request) {
                self::assertSame('POST /batch/ HTTP/1.1', $request['requestLine']);
                self::assertSame($requests[0]['body'], $request['body']);
                self::assertSame($compressed ? 'gzip' : null, $request['headers']['content-encoding'] ?? null);
            }
            self::assertEquals($started->modify('+' . $delayMilliseconds . ' milliseconds'), $clock->now());
            self::assertTrue($client->flush());
            self::assertCount($attempts, $this->server->requests());
        } finally {
            Clock::set($previousClock);
        }
    }
}
