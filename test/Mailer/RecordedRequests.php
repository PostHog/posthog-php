<?php

namespace PostHog\Test\Mailer;

/**
 * The HTTP requests MailerKernel's PostHog transport made, in place of the network.
 */
final class RecordedRequests
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
    public static array $requests = [];

    /**
     * @param list<string> $headers
     * @return array{int, string, list<string>}
     */
    public static function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        self::$requests[] = compact('method', 'url', 'headers', 'body');

        return [202, '{"message_id":"01a18356-c56c-0001-e494-7666bc915188"}', []];
    }
}
