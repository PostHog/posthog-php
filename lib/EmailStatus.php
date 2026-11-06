<?php

namespace PostHog;

use DateTimeImmutable;

/**
 * What happened to an email that Emails::send() queued.
 *
 * The status is one of:
 *
 * - `queued`: the email waits to go out.
 * - `sent`: PostHog handed the email to the email provider.
 * - `failed`: the email was not sent, and the reason says why.
 * - `delivered`: the recipient's mail server accepted the email.
 * - `bounced` and `complained`: the recipient's mail provider reported them after delivery.
 *
 * Later SDK versions can see values this list does not have yet.
 */
class EmailStatus
{
    private string $id;
    private string $status;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $statusAt;
    private ?string $reason;

    /**
     * @internal Created by Emails::get().
     */
    public function __construct(
        string $id,
        string $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $statusAt,
        ?string $reason = null
    ) {
        $this->id = $id;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->statusAt = $statusAt;
        $this->reason = $reason;
    }

    /**
     * The message ID that Emails::send() returned.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The email's status, such as `delivered` or `bounced`.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * When PostHog queued the email.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * When the email reached its current status.
     */
    public function getStatusAt(): DateTimeImmutable
    {
        return $this->statusAt;
    }

    /**
     * Why a failed email was not sent, such as `provider_error` or `recipient_suppressed`, or the bounce
     * type of a bounced email: `hard`, `soft` or `unknown`. Null otherwise.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }
}
