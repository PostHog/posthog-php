<?php

namespace PostHog;

use DateTimeImmutable;

/**
 * The accepted email. A result means PostHog queued or scheduled the email, not that it was delivered.
 */
class SendEmailResult
{
    private string $id;
    private bool $replayed;
    /** @var list<EmailWarning> */
    private array $warnings;
    private ?DateTimeImmutable $scheduledAt = null;

    /**
     * @internal Created by Emails::send().
     * @param list<EmailWarning> $warnings
     */
    public function __construct(
        string $id,
        bool $replayed = false,
        array $warnings = [],
        ?DateTimeImmutable $scheduledAt = null
    ) {
        $this->id = $id;
        $this->replayed = $replayed;
        $this->warnings = $warnings;
        $this->scheduledAt = $scheduledAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $properties = (array) $this;
        if ($this->scheduledAt === null) {
            unset($properties["\0" . self::class . "\0scheduledAt"]);
        }

        return $properties;
    }

    /**
     * The queued email's message ID. Pass it to Emails::get() to read the email's status.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * True when PostHog had already accepted this idempotency key and returned the first send's result.
     */
    public function isReplayed(): bool
    {
        return $this->replayed;
    }

    /**
     * @return list<EmailWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * `scheduled` when PostHog confirmed the email's `scheduled_at`, even if a replay answers after the email
     * went out. `queued` when it goes out now: a send without `scheduled_at`, or one whose `scheduled_at` was at
     * most a minute ahead of the client's clock when it sent the attempt PostHog answered, a replay included.
     */
    public function getStatus(): string
    {
        return $this->scheduledAt === null ? 'queued' : 'scheduled';
    }

    /**
     * When a scheduled email goes out, in UTC, to the millisecond. Null for an email that goes out now.
     */
    public function getScheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }
}
