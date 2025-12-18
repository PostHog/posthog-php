<?php

namespace PostHog;

/**
 * Exception for HTTP-related errors during API requests.
 *
 * This exception captures both the error type and HTTP status code
 * to enable specific error handling for different failure scenarios.
 */
class HttpException extends \Exception
{
    /**
     * Request timed out
     */
    public const TIMEOUT = 'timeout';

    /**
     * Network connectivity issue
     */
    public const CONNECTION_ERROR = 'connection_error';

    /**
     * Rate/quota limit exceeded
     */
    public const QUOTA_LIMITED = 'quota_limited';

    /**
     * HTTP 4xx/5xx error from API
     */
    public const API_ERROR = 'api_error';

    /**
     * @var string
     */
    private string $errorType;

    /**
     * @var int
     */
    private int $statusCode;

    /**
     * @param string $errorType One of the error type constants (TIMEOUT, CONNECTION_ERROR, etc.)
     * @param int $statusCode HTTP status code (0 for connection/timeout errors)
     * @param string $message Error message
     */
    public function __construct(string $errorType, int $statusCode = 0, string $message = '')
    {
        $this->errorType = $errorType;
        $this->statusCode = $statusCode;
        parent::__construct($message);
    }

    /**
     * Get the error type constant
     *
     * @return string One of TIMEOUT, CONNECTION_ERROR, QUOTA_LIMITED, API_ERROR
     */
    public function getErrorType(): string
    {
        return $this->errorType;
    }

    /**
     * Get the HTTP status code
     *
     * @return int HTTP status code (0 for connection/timeout errors)
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
