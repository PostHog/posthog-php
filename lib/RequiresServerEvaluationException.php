<?php

namespace PostHog;

use Exception;
use Throwable;

/**
 * Raised when feature flag evaluation requires server-side data.
 *
 * @internal
 */
class RequiresServerEvaluationException extends Exception
{
    /**
     * @param string $message Exception message.
     * @param int $code Exception code.
     * @param Throwable|null $previous Previous exception.
     * @param string $reason Why local evaluation is inconclusive, one of the UnresolvedFlagReason values.
     *     Server-side data is never available locally, so by default the definition is unsupported.
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
        $errorMsg = 'Error on line ' . $this->getLine() . ' in ' . $this->getFile() . ': <b> Requires Server Evaluation:' . $this->getMessage() . '</b>'; //phpcs:ignore
        return $errorMsg;
    }
}
