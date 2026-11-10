<?php

namespace PostHog;

use RuntimeException;
use Throwable;

/**
 * PostHog did not queue the email, or the client could not tell whether it did.
 *
 * Branch on getErrorCode(), PostHog's stable error code such as `recipient_suppressed`, or an SDK code
 * such as `network_error`. getErrorName() is a coarse category of the failure, such as `validation_error`
 * or `rate_limit_exceeded`. The message never contains the secret key or the request body.
 */
class EmailError extends RuntimeException
{
    private const REFUSED_ATTACHMENT_CODES = ['too_many_attachments', 'message_too_large'];

    private string $errorName;
    private string $errorCode;
    private ?int $statusCode;
    private ?string $why;
    private ?string $fix;
    private ?string $docsUrl;
    private ?string $field;
    private ?int $retryAfterMs;

    /**
     * @param string $errorCode PostHog's error code, or an SDK code such as `network_error`.
     * @param string $message What went wrong.
     * @param int|null $statusCode The HTTP status, when PostHog answered.
     * @param string|null $why Why PostHog refused the request.
     * @param string|null $fix How to fix the request.
     * @param string|null $docsUrl A page that explains the error.
     * @param string|null $field The request field the error is about.
     * @param int|null $retryAfterMs How long PostHog asked the client to wait before it sends again.
     * @param Throwable|null $previous The failure that caused this error.
     */
    public function __construct(
        string $errorCode,
        string $message,
        ?int $statusCode = null,
        ?string $why = null,
        ?string $fix = null,
        ?string $docsUrl = null,
        ?string $field = null,
        ?int $retryAfterMs = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorName = self::nameFor($statusCode, $errorCode);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        $this->why = $why;
        $this->fix = $fix;
        $this->docsUrl = $docsUrl;
        $this->field = $field;
        $this->retryAfterMs = $retryAfterMs;
    }

    /**
     * A coarse category of the failure: `validation_error`, `missing_api_key`, `invalid_permission`,
     * `not_found`, `concurrent_idempotent_requests`, `invalid_idempotent_request`, `rate_limit_exceeded`,
     * `monthly_quota_exceeded`, `service_unavailable` or `application_error`.
     */
    public function getErrorName(): string
    {
        return $this->errorName;
    }

    /**
     * PostHog's error code, such as `quota_limited`, or an SDK code such as `missing_secret_key`,
     * `missing_app_host`, `client_disabled`, `client_closed`, `network_error`, `api_error`, `upload_failed`
     * or an `invalid_*` argument code.
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The HTTP status, or null when PostHog did not answer.
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Why PostHog refused the request, when it said.
     */
    public function getWhy(): ?string
    {
        return $this->why;
    }

    /**
     * How to fix the request, when PostHog or the SDK said.
     */
    public function getFix(): ?string
    {
        return $this->fix;
    }

    /**
     * A page that explains the error, when PostHog sent one.
     */
    public function getDocsUrl(): ?string
    {
        return $this->docsUrl;
    }

    /**
     * The request field the error is about, such as `subject`, when one was named.
     */
    public function getField(): ?string
    {
        return $this->field;
    }

    /**
     * How long PostHog asked the client to wait before it sends again, in milliseconds.
     */
    public function getRetryAfterMs(): ?int
    {
        return $this->retryAfterMs;
    }

    private static function nameFor(?int $status, string $code): string
    {
        if ($code === 'upload_failed') {
            return $status !== null && $status >= 500 ? 'service_unavailable' : 'application_error';
        }
        if ($code === 'missing_secret_key' || $status === 401) {
            return 'missing_api_key';
        }
        if (in_array($code, self::REFUSED_ATTACHMENT_CODES, true)) {
            return 'validation_error';
        }
        if ($status === null) {
            if ($code === 'network_error') {
                return 'service_unavailable';
            }
            return str_starts_with($code, 'invalid_') ? 'validation_error' : 'application_error';
        }

        return match ($status) {
            400 => 'validation_error',
            403 => 'invalid_permission',
            404 => 'not_found',
            409 => $code === 'template_outdated' ? 'validation_error' : 'concurrent_idempotent_requests',
            422 => $code === 'idempotency_key_reused' ? 'invalid_idempotent_request' : 'validation_error',
            429 => $code === 'quota_limited' ? 'monthly_quota_exceeded' : 'rate_limit_exceeded',
            503 => 'service_unavailable',
            default => 'application_error',
        };
    }
}
