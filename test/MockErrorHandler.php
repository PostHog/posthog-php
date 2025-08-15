<?php

namespace PostHog\Test;

/**
 * Mock error handler to capture and verify error reporting
 */
class MockErrorHandler
{
    private $errors = [];

    public function handleError($code, $message)
    {
        $this->errors[] = ['code' => $code, 'message' => $message];
    }

    public function hasError($code)
    {
        foreach ($this->errors as $error) {
            if ($error['code'] === $code) {
                return true;
            }
        }
        return false;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}