<?php

namespace PostHog\Test\Mailer;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * MailerKernel's HTTP client in place of the network: it records each request and PostHog accepts every send.
 */
final class RecordedRequests
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: string}> */
    public static array $requests = [];

    /**
     * @param array{headers: list<string>, body?: string} $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        self::$requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'],
            'body' => $options['body'] ?? ''];

        return new MockResponse('{"message_id":"01a18356-c56c-0001-e494-7666bc915188"}', ['http_code' => 202]);
    }
}
