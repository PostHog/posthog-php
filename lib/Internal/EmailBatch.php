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
 * The recipients of one Emails::batch() call, split into requests, and what PostHog answered for each.
 *
 * A recipient is maybe queued once PostHog may have queued its email: a request for it went unanswered, or
 * PostHog said it couldn't tell. It stays `unconfirmed` until PostHog answers `queued`, since a later refusal
 * doesn't prove the first attempt sent nothing.
 *
 * @internal
 */
final class EmailBatch
{
    private const MAX_RECIPIENTS_PER_REQUEST = 100;
    private const MAX_REQUEST_BYTES = 4 * 1024 * 1024;
    private const RECIPIENT_FIELDS = ['to', 'distinct_id', 'idempotency_key', 'template_data'];
    private const UNCONFIRMED_CODES = ['idempotency_key_in_flight', 'delivery_unavailable', 'api_error'];
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const SEND_SINGLY = "send each email the batch didn't queue with emails()->send() and its recipient's "
        . 'idempotency_key.';

    /** @var list<array{body: string, indices: list<int>}> */
    private array $requests = [];
    /** @var array<int, BatchEmailResult> */
    private array $results = [];
    /** @var array<int, true> */
    private array $maybeQueued = [];
    /** @var list<EmailWarning> */
    private array $warnings = [];

    /**
     * @param list<array{to: string, key: string, wire: string}> $members
     */
    private function __construct(private array $members, string $emptyBody)
    {
        $this->split($emptyBody);
    }

    /**
     * Checks every recipient before any request, and gives each the idempotency key its every attempt carries.
     *
     * @param array<string, mixed> $shared The email every recipient gets, as the request carries it.
     */
    public static function prepare(array $shared, mixed $recipients, bool $isTemplateForm): self
    {
        if (!is_array($recipients) || $recipients === [] || !array_is_list($recipients)) {
            throw new EmailError(
                'invalid_recipients',
                "recipients must be a nonempty list of recipients, such as [['to' => 'user@example.com']], so no "
                . 'email was sent.',
                field: 'recipients'
            );
        }
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

        return new self($members, self::encode([...$shared, 'recipients' => []], 'The email'));
    }

    /**
     * @return array{to: string, key: string, wire: string}
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
        [$to, $distinctId, $key] = EmailRecipient::check($recipient, $index);
        $wire = ['to' => $to, 'idempotency_key' => $key ?? Uuid::v4()];
        if ($distinctId !== null) {
            $wire['distinct_id'] = $distinctId;
        }
        if (array_key_exists('template_data', $recipient)) {
            $wire['message_data'] = self::templateData($index, $recipient['template_data'], $isTemplateForm);
        }

        return ['to' => $to, 'key' => $wire['idempotency_key'], 'wire' => self::encode($wire, "recipients[$index]")];
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

    private static function encode(array $payload, string $what): string
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

    /**
     * Splits the recipients into requests of at most 100 recipients and 4 MiB, in order. A recipient too large for
     * any request fails here and joins no request.
     */
    private function split(string $emptyBody): void
    {
        $indices = [];
        $wires = [];
        $bytes = strlen($emptyBody);
        foreach ($this->members as $index => $member) {
            $memberBytes = strlen($member['wire']);
            if (strlen($emptyBody) + $memberBytes > self::MAX_REQUEST_BYTES) {
                $this->set($index, 'failed', error: self::recipientTooLarge());
                continue;
            }
            $separatorBytes = $indices === [] ? 0 : 1;
            $full = count($indices) === self::MAX_RECIPIENTS_PER_REQUEST;
            if ($indices !== [] && ($full || $bytes + $separatorBytes + $memberBytes > self::MAX_REQUEST_BYTES)) {
                $this->addRequest($emptyBody, $indices, $wires);
                [$indices, $wires, $bytes, $separatorBytes] = [[], [], strlen($emptyBody), 0];
            }
            $indices[] = $index;
            $wires[] = $member['wire'];
            $bytes += $separatorBytes + $memberBytes;
        }
        if ($indices !== []) {
            $this->addRequest($emptyBody, $indices, $wires);
        }
    }

    /**
     * @param list<int> $indices
     * @param list<string> $wires
     */
    private function addRequest(string $emptyBody, array $indices, array $wires): void
    {
        $withoutClosingBrackets = substr($emptyBody, 0, -strlen(']}'));
        $this->requests[] = ['body' => $withoutClosingBrackets . implode(',', $wires) . ']}', 'indices' => $indices];
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    public function requestBody(int $position): string
    {
        return $this->requests[$position]['body'];
    }

    /**
     * An attempt of request `$position` got no answer the client can read, so it may have queued any of its
     * recipients.
     */
    public function lost(int $position): void
    {
        foreach ($this->requests[$position]['indices'] as $index) {
            $this->maybeQueued[$index] = true;
            $messageId = ($this->results[$index] ?? null)?->getMessageId();
            $this->set($index, 'unconfirmed', $messageId, error: self::unconfirmed($messageId));
        }
    }

    /**
     * Records PostHog's answer to request `$position`. Throws the request's error when PostHog refused the whole
     * request or its answer can't be read.
     *
     * @param list<string> $headers
     */
    public function answer(int $position, int $status, string $body, array $headers): void
    {
        $indices = $this->requests[$position]['indices'];
        $fields = EmailReplies::fields($body);
        if ($status < 200 || $status >= 300) {
            if ($status >= 500) {
                $this->lost($position);
            }
            throw self::requestError($fields, $status, $headers, $indices);
        }
        $slots = $fields['results'] ?? null;
        if (!is_array($slots) || !array_is_list($slots) || count($slots) !== count($indices)) {
            $this->lost($position);
            throw new EmailError(
                'api_error',
                'PostHog answered the batch without a result for each recipient.',
                $status
            );
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
        if (isset($this->maybeQueued[$index])) {
            $earlier = $this->results[$index];
            $messageId ??= $earlier->getMessageId();
            $this->set($index, 'unconfirmed', $messageId, error: $earlier->getError(), laterError: $answer);
        } elseif (in_array($answer->getErrorCode(), self::UNCONFIRMED_CODES, true)) {
            $this->maybeQueued[$index] = true;
            $this->set($index, 'unconfirmed', $messageId, error: $answer);
        } else {
            $this->set($index, 'failed', $messageId, error: $answer);
        }
    }

    /**
     * Request `$position` failed as a whole, so the batch stops there.
     */
    public function stop(int $position, EmailError $error): void
    {
        $status = $error->getStatusCode();
        $later = $status !== null && $status < 500 ? $error : null;
        foreach ($this->requests[$position]['indices'] as $index) {
            if (!isset($this->maybeQueued[$index])) {
                $this->set($index, 'failed', error: $error);
            } elseif ($later !== null) {
                $earlier = $this->results[$index];
                $this->set(
                    $index,
                    'unconfirmed',
                    $earlier->getMessageId(),
                    error: $earlier->getError(),
                    laterError: $later
                );
            }
        }
        $this->skipFrom($position + 1, new EmailError(
            'not_attempted',
            'An earlier request of the batch failed, so the client sent nothing for this recipient.'
        ));
    }

    /**
     * The client shut down before request `$position` went out.
     */
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
        foreach (array_slice($this->requests, $position) as $request) {
            foreach ($request['indices'] as $index) {
                $this->set($index, 'not_attempted', error: $error);
            }
        }
    }

    /**
     * @return array<int, string> The idempotency key of each recipient a lost answer left without a message ID,
     *     by its position in `recipients`.
     */
    public function keysToRead(): array
    {
        $keys = [];
        foreach ($this->maybeQueued as $index => $_) {
            if ($this->results[$index]->getMessageId() === null) {
                $keys[$index] = $this->members[$index]['key'];
            }
        }
        ksort($keys);

        return $keys;
    }

    public function accepted(int $index, string $messageId): void
    {
        $this->set($index, 'queued', $messageId);
    }

    public function result(): SendBatchResult
    {
        return new SendBatchResult($this->outcome(), $this->warnings);
    }

    public function failure(EmailError $error): EmailBatchError
    {
        return new EmailBatchError($error, $this->outcome(), $this->warnings);
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
     * PostHog names a recipient by its position in the request. The caller knows it by its position in
     * `recipients`.
     *
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

    private static function recipientTooLarge(): EmailError
    {
        return new EmailError(
            'content_too_large',
            "This recipient's email is larger than one batch request takes, so it wasn't sent.",
            fix: "Send this recipient's email with emails()->send().",
            field: 'recipients'
        );
    }
}
