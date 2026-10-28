<?php

namespace PostHog;

/** @internal */
trait HttpRetryPolicy
{
    protected function isRetryableStatus(int $responseCode): bool
    {
        return $responseCode === 408
            || $responseCode === 429
            || ($responseCode >= 500 && $responseCode <= 600);
    }

    /**
     * Event batches retry any response except a client error, because redirects
     * and other unexpected responses may be transient.
     */
    protected function isRetryableBatchStatus(int $responseCode): bool
    {
        return $responseCode < 400 || $responseCode >= 500 || $this->isRetryableStatus($responseCode);
    }

    /**
     * @param array<int, string> $headers
     */
    protected function retryDelayMilliseconds(array $headers, int $backoff, int $maximumMilliseconds): int
    {
        return min($this->retryAfterMilliseconds($headers) ?? $backoff, $maximumMilliseconds);
    }

    /**
     * @param array<int, string> $headers
     */
    protected function retryAfterMilliseconds(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Retry-After:') !== 0) {
                continue;
            }

            $value = trim(substr($header, strlen('Retry-After:')));
            if ($value === '') {
                return null;
            }

            if (ctype_digit($value)) {
                return min((int) $value, intdiv(PHP_INT_MAX, 1000)) * 1000;
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return max(0, (int) (($timestamp - time()) * 1000));
            }
        }

        return null;
    }
}
