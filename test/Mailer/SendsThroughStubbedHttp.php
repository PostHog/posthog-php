<?php

namespace PostHog\Test\Mailer;

use Closure;
use PostHog\EmailError;
use PostHog\Emails;
use PostHog\Mailer\PostHogTransport;

/**
 * Builds PostHogTransport on the real Emails sender with only its HTTP requests replaced.
 */
trait SendsThroughStubbedHttp
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
    private array $requests = [];
    /** @var list<array{int, string, list<string>}|EmailError> */
    private array $replies = [];

    private function transport(?string $defaultTemplateKey = null, bool $templates = true): PostHogTransport
    {
        return new PostHogTransport($this->emails(), defaultTemplateKey: $defaultTemplateKey, templates: $templates);
    }

    private function emails(): Emails
    {
        return new Emails('phs_secret', 'us.i.posthog.com', transport: Closure::fromCallable([$this, 'sendRequest']));
    }

    /**
     * @param list<string> $headers
     * @return array{int, string, list<string>}
     */
    public function sendRequest(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $reply = array_shift($this->replies) ?? [202, '{"message_id":"01a18356-c56c-0001-e494-7666bc915188"}', []];
        if ($reply instanceof EmailError) {
            throw $reply;
        }

        return $reply;
    }

    /** @param array{int, string, list<string>}|EmailError ...$replies */
    private function reply(...$replies): void
    {
        $this->replies = $replies;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $index = 0): array
    {
        return json_decode($this->requests[$index]['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function header(string $name, int $index = 0): ?string
    {
        foreach ($this->requests[$index]['headers'] as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}
