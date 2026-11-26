<?php

namespace PostHog;

/**
 * What happened to one recipient of Emails::batch().
 *
 * getStatus() is one of:
 *
 * - `queued`: PostHog accepted the email. Read its status with Emails::get() to see whether it went out.
 * - `failed`: PostHog didn't queue the email, and getError() says why, such as a suppressed address.
 * - `unconfirmed`: the client can't tell whether PostHog queued the email, such as after a lost answer. It may
 *   still go out. Send it again with the same idempotency key within 24 hours of its first use, so it goes out
 *   once.
 *   getMessageId() is the email's ID when PostHog's answer named one.
 */
class BatchEmailResult
{
    /**
     * @internal Created by Emails::batch().
     */
    public function __construct(
        private int $index,
        private string $to,
        private string $idempotencyKey,
        private string $status,
        private ?string $messageId = null,
        private bool $replayed = false,
        private ?EmailError $error = null
    ) {
    }

    /**
     * The recipient's position in `recipients`.
     */
    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * The recipient's email address.
     */
    public function getTo(): string
    {
        return $this->to;
    }

    /**
     * The recipient's idempotency key, given or made by the client. Send the email again with this key to make
     * sure it goes out once.
     */
    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    /**
     * `queued`, `failed` or `unconfirmed`.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * The email's message ID when PostHog's answer named it, also for some emails it didn't queue. Pass it to
     * Emails::get() to read the email's status.
     */
    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    /**
     * True when an earlier send with the same idempotency key had already queued the email, so no new email was
     * sent.
     */
    public function isReplayed(): bool
    {
        return $this->replayed;
    }

    /**
     * Why the email is not `queued`, or null when it is. An `unconfirmed` email can carry any code, such as
     * `delivery_unavailable`, `idempotency_key_in_flight`, or the refusal of a retry after a lost answer, so decide
     * whether to send it again from getStatus(), not from the code.
     */
    public function getError(): ?EmailError
    {
        return $this->error;
    }
}
