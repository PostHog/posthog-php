<?php

namespace PostHog;

class FeatureFlagError
{
    /**
     * Server returned errorsWhileComputingFlags=true
     */
    public const ERRORS_WHILE_COMPUTING_FLAGS = 'errors_while_computing_flags';

    /**
     * Requested flag not in API response
     */
    public const FLAG_MISSING = 'flag_missing';

    /**
     * Rate/quota limit exceeded
     */
    public const QUOTA_LIMITED = 'quota_limited';

    /**
     * Request timed out
     */
    public const TIMEOUT = 'timeout';

    /**
     * Network connectivity issue
     */
    public const CONNECTION_ERROR = 'connection_error';

    /**
     * Unexpected exceptions
     */
    public const UNKNOWN_ERROR = 'unknown_error';

    /**
     * Create an API error with HTTP status code
     *
     * @param int $status HTTP status code
     * @return string Error string in format "api_error_{status}"
     */
    public static function apiError(int $status): string
    {
        return "api_error_{$status}";
    }
}
