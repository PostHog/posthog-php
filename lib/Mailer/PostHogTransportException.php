<?php

namespace PostHog\Mailer;

use PostHog\EmailError;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * PostHog did not queue the email, or the client could not tell whether it did. The EmailError is the
 * previous exception.
 */
final class PostHogTransportException extends TransportException
{
    public function __construct(private readonly EmailError $error)
    {
        parent::__construct($error->getMessage(), 0, $error);
    }

    /**
     * The HTTP status, or null when PostHog did not answer or the client refused the email before sending.
     */
    public function getStatusCode(): ?int
    {
        return $this->error->getStatusCode();
    }

    /**
     * PostHog's error code, such as `recipient_suppressed`, or an SDK code such as `network_error`.
     */
    public function getErrorCode(): string
    {
        return $this->error->getErrorCode();
    }

    /**
     * The field of the send the error is about, such as `to`.
     */
    public function getField(): ?string
    {
        return $this->error->getField();
    }

    /**
     * Whether sending the email again with the same idempotency key can succeed. Set the
     * `X-PostHog-Idempotency-Key` header, such as from an order ID, so that a retry never sends twice.
     */
    public function isRetryable(): bool
    {
        return $this->error->isRetryable();
    }

    /**
     * How many seconds PostHog asked the client to wait before it sends again.
     */
    public function getRetryAfter(): ?int
    {
        $retryAfterMs = $this->error->getRetryAfterMs();

        return $retryAfterMs === null ? null : (int) ceil($retryAfterMs / 1000);
    }
}
