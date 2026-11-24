<?php

namespace PostHog\Internal;

use JsonException;
use PostHog\BatchEmailResult;
use PostHog\EmailBatchError;
use PostHog\EmailError;
use PostHog\EmailWarning;
use PostHog\SendBatchResult;
use PostHog\Uuid;

/**
 * @internal
 */
final class EmailBatch
{
    private const MAX_RECIPIENTS_PER_REQUEST = 100;
    private const MAX_REQUEST_BYTES = 4 * 1024 * 1024;
    private const RECIPIENT_FIELDS = ['to', 'distinct_id', 'idempotency_key', 'template_data'];
    private const UNCONFIRMED_CODES = ['idempotency_key_in_flight', 'delivery_unavailable', 'api_error'];
    private const IN_FLIGHT = 'idempotency_key_in_flight';
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const SEND_SINGLY = "send each email the batch didn't queue with emails()->send() and its recipient's "
        . 'idempotency_key.';

    /** @var list<list<int>> The positions in `recipients` that each request carries. */
    private array $requests = [];
    /** @var array<int, BatchEmailResult> */
    private array $results = [];
    /** @var list<EmailWarning> */
    private array $warnings = [];

    /**
     * @param list<array{to: string, key: string, bytes: int, wire: ?string, field: string}> $members
     * @param string $opening The request body up to its first recipient.
     */
    private function __construct(private array $members, private string $opening)
    {
        $this->split();
    }

    /**
     * @param array<string, mixed> $shared The email every recipient gets, as the request carries it.
     */
    public static function prepare(array $shared, mixed $recipients): self
    {
        if (!is_array($recipients) || $recipients === [] || !array_is_list($recipients)) {
            throw new EmailError(
                'invalid_recipients',
                "recipients must be a nonempty list of recipients, such as [['to' => 'user@example.com']], so no "
                . 'email was sent.',
                field: 'recipients'
            );
        }
        $encoded = [];
        $size = strlen('{"recipients":[]}');
        foreach ($shared as $name => $value) {
            $encoded[$name] = self::encode($value, 'The email');
            $size += strlen(self::encode((string) $name, 'The email')) + strlen($encoded[$name]) + strlen(':,');
        }
        if ($size > self::MAX_REQUEST_BYTES) {
            throw self::sharedTooLarge($encoded);
        }
        $pairs = [];
        foreach ($encoded as $name => $json) {
            $pairs[] = self::encode((string) $name, 'The email') . ':' . $json;
        }
        $opening = '{' . implode(',', [...$pairs, '"recipients":[']);
        $isTemplateForm = array_key_exists('template_id', $shared);
        $members = [];
        $firstWithKey = [];
        foreach ($recipients as $index => $recipient) {
            $member = self::member($index, $recipient, $isTemplateForm);
            $first = $firstWithKey[$member['key']] ??= $index;
            if ($first !== $index) {
                throw new EmailError(
                    'invalid_idempotency_key',
                    "recipients[$index] has the same idempotency_key as recipients[$first], so no email was sent.",
                    why: 'PostHog sends one email per key, so the second recipient would get no email.',
                    fix: 'Give each recipient its own key.',
                    field: "recipients__{$index}__idempotency_key"
                );
            }
            $members[] = $member;
        }

        return new self($members, $opening);
    }

    /**
     * @return array{to: string, key: string, bytes: int, wire: ?string, field: string}
     */
    private static function member(int $index, mixed $recipient, bool $isTemplateForm): array
    {
        if (!is_array($recipient) || ($recipient !== [] && array_is_list($recipient))) {
            throw new EmailError(
                'invalid_recipient',
                "recipients[$index] must be an array with to, such as ['to' => 'user@example.com'], so no email "
                . 'was sent.',
                field: "recipients__$index"
            );
        }
        $recipient = array_filter($recipient, static fn($value): bool => $value !== null);
        foreach (array_keys($recipient) as $name) {
            if (!in_array($name, self::RECIPIENT_FIELDS, true)) {
                throw new EmailError(
                    'invalid_form',
                    "recipients[$index] takes only to, distinct_id, idempotency_key and template_data, not $name, so "
                    . 'no email was sent.',
                    fix: 'Set the rest on the batch, or send this email with emails()->send().',
                    field: "recipients__{$index}__$name"
                );
            }
        }
        [$to, $distinctId] = EmailRecipient::address($recipient, $index);
        $wire = ['to' => $to, 'idempotency_key' => EmailRecipient::idempotencyKey($recipient, $index) ?? Uuid::v4()];
        if ($distinctId !== null) {
            $wire['distinct_id'] = $distinctId;
        }
        if (array_key_exists('template_data', $recipient)) {
            $wire['message_data'] = self::templateData($index, $recipient['template_data'], $isTemplateForm);
        }
        $values = [];
        $sizes = [];
        $bytes = strlen('{}') + count($wire) - 1;
        foreach ($wire as $name => $value) {
            $values[$name] = self::encode($value, "recipients[$index]");
            $sizes[$name === 'message_data' ? 'template_data' : $name] = strlen($values[$name]);
            $bytes += strlen("\"$name\":") + strlen($values[$name]);
        }
        $named = array_intersect_key($sizes, ['distinct_id' => true, 'template_data' => true]);
        arsort($named);
        $largest = array_key_first($named);

        return [
            'to' => $to,
            'key' => $wire['idempotency_key'],
            'bytes' => $bytes,
            'wire' => $bytes > self::MAX_REQUEST_BYTES ? null : self::joined($values),
            'field' => $largest === null ? "recipients__$index" : "recipients__{$index}__$largest",
        ];
    }

    /**
     * @param array<string, string> $values
     */
    private static function joined(array $values): string
    {
        $pairs = [];
        foreach ($values as $name => $json) {
            $pairs[] = "\"$name\":$json";
        }

        return '{' . implode(',', $pairs) . '}';
    }

    private static function templateData(int $index, mixed $data, bool $isTemplateForm): array|object
    {
        $field = "recipients__{$index}__template_data";
        if (!$isTemplateForm) {
            throw new EmailError(
                'invalid_form',
                "recipients[$index] has template_data, but a batch with template_key sends the same content to every "
                . 'recipient, so no email was sent.',
                fix: 'Send personalized content with a template_id batch, or with emails()->send().',
                field: $field
            );
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new EmailError(
                'invalid_template_data',
                "recipients[$index]: template_data must be an array with a value per variable name.",
                field: $field
            );
        }

        return $data === [] ? (object) [] : $data;
    }

    private static function encode(mixed $payload, string $what): string
    {
        try {
            return json_encode($payload, self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw new EmailError(
                'invalid_form',
                "$what could not be encoded as JSON, so no email was sent: " . $exception->getMessage() . '.',
                previous: $exception
            );
        }
    }

    private function split(): void
    {
        $emptyBytes = strlen($this->opening) + strlen(']}');
        $indices = [];
        $bytes = $emptyBytes;
        foreach ($this->members as $index => $member) {
            $memberBytes = $member['bytes'];
            if ($emptyBytes + $memberBytes > self::MAX_REQUEST_BYTES) {
                $this->set($index, 'failed', error: self::recipientTooLarge($member['field']));
                continue;
            }
            $separatorBytes = $indices === [] ? 0 : 1;
            $full = count($indices) === self::MAX_RECIPIENTS_PER_REQUEST;
            if ($indices !== [] && ($full || $bytes + $separatorBytes + $memberBytes > self::MAX_REQUEST_BYTES)) {
                $this->requests[] = $indices;
                [$indices, $bytes, $separatorBytes] = [[], $emptyBytes, 0];
            }
            $indices[] = $index;
            $bytes += $separatorBytes + $memberBytes;
        }
        if ($indices !== []) {
            $this->requests[] = $indices;
        }
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    public function requestBody(int $position): string
    {
        $wires = array_map(fn(int $index): string => $this->members[$index]['wire'], $this->requests[$position]);

        return $this->opening . implode(',', $wires) . ']}';
    }

    public function lost(int $position, EmailError $error): void
    {
        $this->recordLaterError($position, $error);
        foreach ($this->requests[$position] as $index) {
            if (!$this->isUnconfirmed($index)) {
                $this->set($index, 'unconfirmed', error: self::unconfirmed(null));
            }
        }
    }

    /**
     * @param list<string> $headers
     */
    public function answer(int $position, int $status, string $body, array $headers): void
    {
        $indices = $this->requests[$position];
        $fields = EmailReplies::fields($body);
        if ($status < 200 || $status >= 300) {
            $error = self::requestError($fields, $status, $headers, $indices);
            if ($status >= 500 || $error->getErrorCode() === self::IN_FLIGHT) {
                $this->lost($position, $error);
            } else {
                $this->recordLaterError($position, $error);
            }
            throw $error;
        }
        $slots = $fields['results'] ?? null;
        if (!is_array($slots) || !array_is_list($slots) || count($slots) !== count($indices)) {
            $error = new EmailError(
                'api_error',
                'PostHog answered the batch without a result for each recipient.',
                $status
            );
            $this->lost($position, $error);
            throw $error;
        }
        foreach ($indices as $slot => $index) {
            $this->settle($index, is_array($slots[$slot]) ? $slots[$slot] : []);
        }
        foreach (EmailReplies::warnings($fields) as $warning) {
            if (!in_array($warning, $this->warnings)) {
                $this->warnings[] = $warning;
            }
        }
    }

    private function recordLaterError(int $position, EmailError $error): void
    {
        foreach ($this->requests[$position] as $index) {
            if ($this->isUnconfirmed($index)) {
                $earlier = $this->results[$index];
                $this->set(
                    $index,
                    'unconfirmed',
                    $earlier->getMessageId(),
                    error: $earlier->getError(),
                    laterError: $error
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $slot
     */
    private function settle(int $index, array $slot): void
    {
        $messageId = EmailReplies::text($slot['message_id'] ?? null);
        $error = $slot['error'] ?? null;
        if ($error === null && ($slot['status'] ?? null) === 'queued' && $messageId !== null) {
            $this->set($index, 'queued', $messageId, ($slot['replayed'] ?? null) === true);
            return;
        }
        $answer = self::slotError($error, $messageId);
        if ($this->isUnconfirmed($index)) {
            $earlier = $this->results[$index];
            $messageId ??= $earlier->getMessageId();
            $this->set($index, 'unconfirmed', $messageId, error: $earlier->getError(), laterError: $answer);
        } elseif (in_array($answer->getErrorCode(), self::UNCONFIRMED_CODES, true)) {
            $this->set($index, 'unconfirmed', $messageId, error: $answer);
        } else {
            $this->set($index, 'failed', $messageId, error: $answer);
        }
    }

    public function stop(int $position, EmailError $error): void
    {
        foreach ($this->requests[$position] as $index) {
            if (!$this->isUnconfirmed($index)) {
                $this->set($index, 'failed', error: $error);
            }
        }
        $this->skipFrom($position + 1, new EmailError(
            'not_attempted',
            'An earlier request of the batch failed, so the client sent nothing for this recipient.'
        ));
    }

    public function closedAt(int $position): EmailError
    {
        $error = new EmailError(
            'client_closed',
            "The PostHog client is shut down, so the rest of the batch wasn't sent."
        );
        $this->skipFrom($position, $error);

        return $error;
    }

    private function skipFrom(int $position, EmailError $error): void
    {
        foreach (array_slice($this->requests, $position) as $indices) {
            foreach ($indices as $index) {
                $this->set($index, 'not_attempted', error: $error);
            }
        }
    }

    public function result(): SendBatchResult
    {
        return new SendBatchResult($this->outcome(), $this->warnings);
    }

    public function failure(EmailError $error): EmailBatchError
    {
        return new EmailBatchError($error, $this->outcome(), $this->warnings);
    }

    private function isUnconfirmed(int $index): bool
    {
        return ($this->results[$index] ?? null)?->getStatus() === 'unconfirmed';
    }

    /**
     * @return list<BatchEmailResult>
     */
    private function outcome(): array
    {
        $results = [];
        foreach (array_keys($this->members) as $index) {
            $results[] = $this->results[$index];
        }

        return $results;
    }

    private function set(
        int $index,
        string $status,
        ?string $messageId = null,
        bool $replayed = false,
        ?EmailError $error = null,
        ?EmailError $laterError = null
    ): void {
        $member = $this->members[$index];
        $this->results[$index] = new BatchEmailResult(
            $index,
            $member['to'],
            $member['key'],
            $status,
            $messageId,
            $replayed,
            $error,
            $laterError
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $headers
     * @param list<int> $indices
     */
    private static function requestError(array $fields, int $status, array $headers, array $indices): EmailError
    {
        $answered = array_filter($fields, static fn($value): bool => $value !== null);
        if (is_string($answered['attr'] ?? null)) {
            $answered['attr'] = self::recipientsField($answered['attr'], $indices);
        }
        if ($status === 404) {
            $answered += [
                'code' => 'not_found',
                'detail' => 'PostHog found no batch send for this request, so no email was sent.',
                'why' => "The template doesn't exist, or batch sends are off for this project or this PostHog version.",
                'fix' => 'Check template_id, or ' . self::SEND_SINGLY,
            ];
        }
        if (($answered['code'] ?? null) === 'batch_unavailable') {
            $answered['fix'] = 'Until batch sends are on for the project, ' . self::SEND_SINGLY;
        }

        return EmailReplies::error($answered, $status, $headers);
    }

    /**
     * @param list<int> $indices
     */
    private static function recipientsField(string $attr, array $indices): string
    {
        if (preg_match('/^recipients__([0-9]+)((?:__.*)?)$/sD', $attr, $match) !== 1) {
            return $attr;
        }
        [, $position, $rest] = $match;
        if (strlen($position) > strlen((string) count($indices)) || (int) $position >= count($indices)) {
            return 'recipients';
        }

        return 'recipients__' . $indices[(int) $position] . $rest;
    }

    private static function slotError(mixed $error, ?string $messageId): EmailError
    {
        if (!is_array($error)) {
            return new EmailError(
                'api_error',
                'PostHog answered without a readable result for this email, so it may still go out.',
                messageId: $messageId
            );
        }
        $code = EmailReplies::text($error['code'] ?? null) ?? 'api_error';
        $status = $error['status'] ?? null;

        return new EmailError(
            $code,
            EmailReplies::text($error['detail'] ?? null) ?? "PostHog didn't queue this email: $code.",
            is_int($status) ? $status : null,
            messageId: $messageId
        );
    }

    private static function unconfirmed(?string $messageId): EmailError
    {
        return new EmailError(
            'delivery_unavailable',
            "PostHog didn't confirm this email, so it may still go out. Send it again with the same idempotency_key "
            . 'within 24 hours, so it goes out once.',
            messageId: $messageId
        );
    }

    /**
     * @param array<string, string> $encoded
     */
    private static function sharedTooLarge(array $encoded): EmailError
    {
        $sizes = [];
        foreach (array_diff_key($encoded, ['template_id' => true, 'template_key' => true]) as $name => $json) {
            $sizes[$name === 'message_data' ? 'template_data' : $name] = strlen($json);
        }
        arsort($sizes);
        $field = (string) array_key_first($sizes);

        return new EmailError(
            'content_too_large',
            'The email every recipient of the batch gets is larger than one batch request takes (4 MiB of JSON), so '
            . "no email was sent. Its largest part is $field.",
            fix: "Make $field smaller, such as by linking to images instead of embedding them, or send each email "
                . 'with emails()->send().',
            field: $field
        );
    }

    private static function recipientTooLarge(string $field): EmailError
    {
        return new EmailError(
            'content_too_large',
            "This recipient's email is larger than one batch request takes, so it wasn't sent.",
            fix: "Send this recipient's email with emails()->send().",
            field: $field
        );
    }
}
