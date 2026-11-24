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
    private const MAX_RECIPIENTS = 100;
    private const RECIPIENT_FIELDS = ['to', 'distinct_id', 'idempotency_key', 'template_data'];
    private const UNCONFIRMED_CODES = ['idempotency_key_in_flight', 'delivery_unavailable', 'api_error'];
    private const SEND_AGAIN = 'Send it again with the same idempotency_key within 24 hours, so it goes out once.';
    private const IN_FLIGHT = 'idempotency_key_in_flight';
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const SEND_SINGLY = "send each email the batch didn't queue with emails()->send() and its recipient's "
        . 'idempotency_key.';

    /** @var array<int, BatchEmailResult> */
    private array $results = [];
    /** @var list<EmailWarning> */
    private array $warnings = [];
    private bool $mayHaveQueued = false;

    /**
     * @param list<array{to: string, key: string}> $members
     */
    private function __construct(private array $members, private string $body)
    {
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
        if (count($recipients) > self::MAX_RECIPIENTS) {
            throw new EmailError(
                'invalid_recipients',
                'recipients takes at most ' . self::MAX_RECIPIENTS . ' recipients, so no email was sent.',
                fix: 'Send the recipients in batches of at most ' . self::MAX_RECIPIENTS . '.',
                field: 'recipients'
            );
        }
        $isTemplateForm = array_key_exists('template_id', $shared);
        $members = [];
        $wires = [];
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
            $members[] = ['to' => $member['to'], 'key' => $member['key']];
            $wires[] = $member['wire'];
        }
        $request = [...$shared, 'recipients' => $wires];
        try {
            return new self($members, json_encode($request, self::JSON_FLAGS));
        } catch (JsonException) {
            self::encode($shared, 'The email');
            foreach ($wires as $index => $wire) {
                self::encode($wire, "recipients[$index]", 510);
            }

            return new self($members, self::encode($request, 'The email'));
        }
    }

    /**
     * @return array{to: string, key: string, wire: array<string, mixed>}
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
        return ['to' => $to, 'key' => $wire['idempotency_key'], 'wire' => $wire];
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

    private static function encode(mixed $payload, string $what, int $depth = 512): string
    {
        try {
            return json_encode($payload, self::JSON_FLAGS, $depth);
        } catch (JsonException $exception) {
            throw new EmailError(
                'invalid_form',
                "$what could not be encoded as JSON, so no email was sent: " . $exception->getMessage() . '.',
                previous: $exception
            );
        }
    }

    public function requestBody(): string
    {
        return $this->body;
    }

    public function lost(EmailError $error): EmailError
    {
        $this->mayHaveQueued = true;
        $inFlight = $error->getStatusCode() === 409 && $error->getErrorCode() === self::IN_FLIGHT;
        $cause = $inFlight ? self::sendAgain($error, $error->getFix() ?? self::SEND_AGAIN) : self::unconfirmed(null);
        $this->setAll('unconfirmed', $cause);

        return $error;
    }

    /**
     * @param list<string> $headers
     */
    public function answer(int $status, string $body, array $headers): void
    {
        $fields = EmailReplies::fields($body);
        if ($status < 200 || $status >= 300) {
            $error = self::requestError($fields, $status, $headers);
            if ($status >= 500 || ($status === 409 && $error->getErrorCode() === self::IN_FLIGHT)) {
                throw $this->lost($error);
            }
            if ($this->mayHaveQueued) {
                $this->setAll('unconfirmed', self::sendAgain($error, self::SEND_AGAIN));
            }
            throw $error;
        }
        $slots = $fields['results'] ?? null;
        if (!is_array($slots) || !array_is_list($slots) || count($slots) !== count($this->members)) {
            throw $this->lost(new EmailError(
                'api_error',
                'PostHog answered the batch without a result for each recipient.',
                $status
            ));
        }
        foreach (array_keys($this->members) as $index) {
            $this->settle($index, is_array($slots[$index]) ? $slots[$index] : []);
        }
        $this->warnings = EmailReplies::warnings($fields);
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
        if ($this->mayHaveQueued || in_array($answer->getErrorCode(), self::UNCONFIRMED_CODES, true)) {
            $this->set($index, 'unconfirmed', $messageId, error: self::sendAgain($answer, self::SEND_AGAIN));
            return;
        }
        $this->set($index, 'failed', $messageId, error: $answer);
    }

    public function result(): SendBatchResult
    {
        return new SendBatchResult($this->outcome(), $this->warnings);
    }

    public function failure(EmailError $error): EmailBatchError
    {
        if (!$this->mayHaveQueued) {
            $this->setAll('failed', $error);
        }

        return new EmailBatchError($error, $this->outcome(), $this->warnings);
    }

    private function setAll(string $status, EmailError $error): void
    {
        foreach (array_keys($this->members) as $index) {
            $this->set($index, $status, error: $error);
        }
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
        ?EmailError $error = null
    ): void {
        $member = $this->members[$index];
        $this->results[$index] = new BatchEmailResult(
            $index,
            $member['to'],
            $member['key'],
            $status,
            $messageId,
            $replayed,
            $error
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $headers
     */
    private static function requestError(array $fields, int $status, array $headers): EmailError
    {
        $answered = array_filter($fields, static fn($value): bool => $value !== null);
        if ($status === 404) {
            $answered += [
                'code' => 'not_found',
                'detail' => 'PostHog found no batch send for this request, so no email was sent.',
                'why' => "The template doesn't exist, or batch sends are off for this project or this PostHog version.",
                'fix' => 'Check template_id, or ' . self::SEND_SINGLY,
            ];
        }
        if ($status === 413) {
            $answered['fix'] = EmailReplies::text($answered['fix'] ?? null)
                ?? 'Make the email or its template_data smaller, or send fewer recipients in each batch.';
        }
        if (($answered['code'] ?? null) === 'batch_unavailable') {
            $answered['fix'] = 'Until batch sends are on for the project, ' . self::SEND_SINGLY;
        }

        return EmailReplies::error($answered, $status, $headers);
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
            "PostHog didn't confirm this email, so it may still go out. " . self::SEND_AGAIN,
            messageId: $messageId
        );
    }

    private static function sendAgain(EmailError $error, string $fix): EmailError
    {
        return new EmailError(
            $error->getErrorCode(),
            $error->getMessage(),
            $error->getStatusCode(),
            $error->getWhy(),
            $fix,
            $error->getDocsUrl(),
            $error->getField(),
            $error->getRetryAfterMs(),
            $error,
            $error->getMessageId()
        );
    }
}
