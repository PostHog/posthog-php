<?php

namespace PostHog\Internal;

use DateTimeImmutable;
use DateTimeZone;
use PostHog\EmailError;
use PostHog\EmailWarning;
use Symfony\Component\Clock\Clock;

/**
 * @internal
 */
final class EmailReplies
{
    private const MAX_RETRY_AFTER_SECONDS = 31_536_000;

    /**
     * @return array<string, mixed> The answer's JSON object, or no fields when it isn't one.
     */
    public static function fields(string $body): array
    {
        $payload = json_decode($body, true);
        return is_array($payload) && !array_is_list($payload) ? $payload : [];
    }

    public static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $headers
     */
    public static function error(array $fields, int $status, array $headers): EmailError
    {
        return new EmailError(
            self::text($fields['code'] ?? null) ?? 'api_error',
            self::text($fields['detail'] ?? null) ?? "PostHog answered HTTP $status.",
            $status,
            why: self::text($fields['why'] ?? null),
            fix: self::text($fields['fix'] ?? null),
            docsUrl: self::text($fields['docs_url'] ?? null),
            field: self::text($fields['attr'] ?? null),
            retryAfterMs: self::retryAfterMs($headers)
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @return list<EmailWarning>
     */
    public static function warnings(array $fields): array
    {
        $warnings = [];
        foreach (is_array($fields['warnings'] ?? null) ? $fields['warnings'] : [] as $warning) {
            if (is_array($warning)) {
                $warnings[] = new EmailWarning(
                    self::text($warning['code'] ?? null) ?? 'warning',
                    self::text($warning['detail'] ?? null) ?? self::text($warning['message'] ?? null) ?? '',
                    self::text($warning['fix'] ?? null)
                );
            }
        }

        return $warnings;
    }

    /**
     * @param list<string> $headers
     */
    public static function retryAfterMs(array $headers): ?int
    {
        $value = null;
        foreach ($headers as $header) {
            if (stripos($header, 'retry-after:') === 0) {
                $value = trim(substr($header, strlen('retry-after:')));
            }
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $seconds = (float) $value;
        } else {
            $date = self::httpDateInUtc($value);
            if ($date === null) {
                return null;
            }
            $seconds = self::epochSecondsAsFloat($date) - self::epochSecondsAsFloat(Clock::get()->now());
        }

        return (int) (min(max(0.0, $seconds), self::longestRetryAfterSecondsAnIntHoldsInMs()) * 1000);
    }

    private static function httpDateInUtc(string $value): ?DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat(DATE_RFC7231, $value, new DateTimeZone('UTC')) ?: null;
    }

    private static function epochSecondsAsFloat(DateTimeImmutable $time): float
    {
        return (float) $time->format('U.u');
    }

    private static function longestRetryAfterSecondsAnIntHoldsInMs(): int
    {
        return min(self::MAX_RETRY_AFTER_SECONDS, intdiv(PHP_INT_MAX, 1000));
    }
}
