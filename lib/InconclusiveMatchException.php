<?php

namespace PostHog;

use Exception;

/**
 * Raised when a feature flag cannot be evaluated conclusively with local data.
 *
 * @internal
 */
class InconclusiveMatchException extends Exception
{
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
