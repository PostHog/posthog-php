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
                return max(0, (int) $value * 1000);
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return max(0, (int) (($timestamp - time()) * 1000));
            }
        }

        return null;
    }
}
