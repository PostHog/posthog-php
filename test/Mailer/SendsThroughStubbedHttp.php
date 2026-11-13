<?php

namespace PostHog\Test\Mailer;

use Closure;
use PostHog\EmailError;
use PostHog\Emails;
use PostHog\Mailer\PostHogTransport;

/**
 * Builds PostHogTransport on the real Emails sender, with FakePostHog in place of the network.
 */
trait SendsThroughStubbedHttp
{
    private ?FakePostHog $posthog = null;

    private function transport(?string $defaultTemplateKey = null, bool $templates = true): PostHogTransport
    {
        return new PostHogTransport($this->emails(), defaultTemplateKey: $defaultTemplateKey, templates: $templates);
    }

    private function emails(): Emails
    {
        return new Emails(
            FakePostHog::SECRET_KEY,
            'us.i.posthog.com',
            transport: Closure::fromCallable($this->posthog())
        );
    }

    private function posthog(): FakePostHog
    {
        return $this->posthog ??= new FakePostHog();
    }

    /** @param array{int, string, list<string>}|EmailError ...$replies The send route's next answers. */
    private function reply(...$replies): void
    {
        $this->posthog()->sendReplies = $replies;
    }

    /**
     * Every request the transport made, to any route or to storage.
     *
     * @return list<array{method: string, url: string, headers: list<string>, body: ?string}>
     */
    private function requests(): array
    {
        return $this->posthog()->requests;
    }

    /**
     * The nth request to the send route, decoded.
     *
     * @return array<string, mixed>
     */
    private function body(int $index = 0): array
    {
        return $this->posthog()->sends()[$index];
    }

    private function header(string $name, int $index = 0): ?string
    {
        return $this->posthog()->sendHeader($name, $index);
    }
}
