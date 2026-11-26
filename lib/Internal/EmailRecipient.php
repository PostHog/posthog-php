<?php

namespace PostHog\Internal;

use PostHog\EmailError;

/**
 * @internal
 */
final class EmailRecipient
{
    private const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7e]{1,255}$/D';

    /**
     * @param array<string, mixed> $fields The recipient's fields, without nulls.
     * @param int|null $index The recipient's position in a batch's `recipients`, or null for a single send.
     * @return array{string, ?string} The address and the distinct ID.
     */
    public static function address(array $fields, ?int $index = null): array
    {
        $to = $fields['to'] ?? null;
        if (!is_string($to) || trim($to) === '') {
            $message = 'to must be one email address as a nonempty string.';
            throw self::invalid('invalid_recipient', $message, 'to', $index);
        }
        $distinctId = $fields['distinct_id'] ?? null;
        if ($distinctId !== null && (!is_string($distinctId) || $distinctId === '')) {
            throw self::invalid('invalid_distinct_id', 'distinct_id must be a nonempty string.', 'distinct_id', $index);
        }

        return [$to, $distinctId];
    }

    /**
     * @param array<string, mixed> $fields The recipient's fields, without nulls.
     * @param int|null $index The recipient's position in a batch's `recipients`, or null for a single send.
     */
    public static function idempotencyKey(array $fields, ?int $index = null): ?string
    {
        $idempotencyKey = $fields['idempotency_key'] ?? null;
        if (
            $idempotencyKey !== null
            && (!is_string($idempotencyKey) || preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1)
        ) {
            throw self::invalid(
                'invalid_idempotency_key',
                'idempotency_key must be 1 to 255 visible ASCII characters without spaces.',
                'idempotency_key',
                $index
            );
        }

        return $idempotencyKey;
    }

    private static function invalid(string $code, string $message, string $field, ?int $index): EmailError
    {
        if ($index === null) {
            return new EmailError($code, $message, field: $field);
        }

        return new EmailError($code, "recipients[$index]: $message", field: "recipients__{$index}__$field");
    }
}
