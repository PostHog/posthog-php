<?php

namespace PostHog\Internal;

use DateTimeImmutable;
use PostHog\EmailWarning;
use PostHog\SendEmailResult;

/**
 * @internal
 */
final class ScheduledEmailResult extends SendEmailResult
{
    private DateTimeImmutable $scheduledAt;

    /**
     * @param list<EmailWarning> $warnings
     */
    public function __construct(string $id, bool $replayed, array $warnings, DateTimeImmutable $scheduledAt)
    {
        parent::__construct($id, $replayed, $warnings);
        $this->scheduledAt = $scheduledAt;
    }

    public function getStatus(): string
    {
        return 'scheduled';
    }

    public function getScheduledAt(): DateTimeImmutable
    {
        return $this->scheduledAt;
    }
}
