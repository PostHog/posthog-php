<?php

namespace PostHog;

/**
 * The queued email. A result means PostHog queued the email, not that it was delivered.
 */
class SendEmailResult
{
    private string $id;
    private bool $replayed;
    /** @var list<EmailWarning> */
    private array $warnings;

    /**
     * @internal Created by Emails::send().
     * @param list<EmailWarning> $warnings
     */
    public function __construct(string $id, bool $replayed = false, array $warnings = [])
    {
        $this->id = $id;
        $this->replayed = $replayed;
        $this->warnings = $warnings;
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
}
