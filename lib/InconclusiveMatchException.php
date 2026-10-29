<?php

namespace PostHog;

use Exception;
use Throwable;

/**
 * Raised when a feature flag cannot be evaluated conclusively with local data.
 *
 * @internal
 */
class InconclusiveMatchException extends Exception
{
    /**
     * @param string $message Exception message.
     * @param int $code Exception code.
     * @param Throwable|null $previous Previous exception.
     * @param string $reason Why local evaluation is inconclusive, one of the UnresolvedFlagReason values.
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        private readonly string $reason = UnresolvedFlagReason::UNSUPPORTED_DEFINITION
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Why local evaluation is inconclusive, one of the UnresolvedFlagReason values.
     *
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Format the exception as an HTML-ish error message.
     *
     * @return string
     */
    public function errorMessage()
    {
        $errorMsg = 'Error on line ' . $this->getLine() . ' in ' . $this->getFile() . ': <b> Inconclusive Match:' . $this->getMessage() . '</b>'; //phpcs:ignore
        return $errorMsg;
    }
}
