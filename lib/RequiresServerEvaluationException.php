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
