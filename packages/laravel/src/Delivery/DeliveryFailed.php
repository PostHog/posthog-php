<?php

namespace PostHog\Laravel\Delivery;

use RuntimeException;
use Throwable;

/** @internal Delivery failures must not generate more analytics delivery jobs. */
final class DeliveryFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = true, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
