<?php

namespace PostHog;

/**
 * A warning PostHog returned with a queued email.
 */
class EmailWarning
{
    private string $code;
    private string $message;
    private ?string $fix;

    /**
     * @internal Created by Emails::send().
     */
    public function __construct(string $code, string $message, ?string $fix = null)
    {
        $this->code = $code;
        $this->message = $message;
        $this->fix = $fix;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getFix(): ?string
    {
        return $this->fix;
    }
}
