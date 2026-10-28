<?php

namespace PostHog;

use Exception;

/**
 * Raised when feature flag evaluation requires server-side data.
 *
 * @internal
 */
class RequiresServerEvaluationException extends Exception
{
    /**
     * Why local evaluation is inconclusive. Server-side data is never available locally, so the
     * definition is unsupported.
     *
     * @return string
     */
    public function getReason(): string
    {
        return UnresolvedFlagReason::UNSUPPORTED_DEFINITION;
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
