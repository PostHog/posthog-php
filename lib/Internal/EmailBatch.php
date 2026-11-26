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
    private const SEND_AGAIN =
        'Send it again with the same idempotency_key within 24 hours of its first use, so it goes out once.';
    private const RESEND_EACH = 'Send each recipient again with the same idempotency_key within 24 hours of its '
        . 'first use, so each email goes out once.';
    private const IN_FLIGHT = 'idempotency_key_in_flight';
    private const BATCH_UNAVAILABLE = 'batch_unavailable';
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const JSON_DEPTH = 514;
    private const SEND_SINGLY = "send each email the batch didn't queue with emails()->send() and its recipient's "
        . 'idempotency_key.';
    private const SEND_EACH_ON_ITS_OWN = 'send each recipient on its own with emails()->send() and its '
        . 'idempotency_key until batch sends are available.';

    /** @var array<int, BatchEmailResult> */
    private array $results = [];
    /** @var list<EmailWarning> */
    private array $warnings = [];
    /** @var array<array-key, array<array-key, array<array-key, true>>> */
    private array $warningKeys = [];
    private bool $mayHaveQueued = false;

    /**
     * @param list<array{to: string, key: string}> $members
     */
    private function __construct(private array $members, private string $body, private bool $isTemplateForm)
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
            return new self(
                $members,
                json_encode($request, self::JSON_FLAGS, self::JSON_DEPTH),
                $isTemplateForm
            );
        } catch (JsonException $exception) {
            throw new EmailError(
                'invalid_form',
                'The email could not be encoded as JSON, so no email was sent: ' . $exception->getMessage() . '.',
                previous: $exception
            );
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


    public function requestBody(): string
    {
        return $this->body;
    }

    public function lost(EmailError $error): EmailError
    {
        $this->mayHaveQueued = true;
        $fix = $error->getErrorCode() === self::IN_FLIGHT ? $error->getFix() ?? self::SEND_AGAIN : self::SEND_AGAIN;
        $cause = $error->getStatusCode() === 409 ? self::unconfirmedBy($error, $fix) : self::unconfirmed(null);
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
            $mayHaveQueuedThisOne = $status >= 500
                || ($status === 409 && EmailReplies::text($fields['code'] ?? null) !== self::BATCH_UNAVAILABLE);
            $error = $this->requestError($fields, $status, $headers);
            if ($mayHaveQueuedThisOne) {
                $lostBefore = $this->mayHaveQueued;
                $this->lost($error);
                throw $lostBefore && $error->getFix() === null ? self::withFix($error, self::RESEND_EACH) : $error;
            }
            if ($this->mayHaveQueued) {
                $this->setAll('unconfirmed', self::unconfirmedBy($error, self::SEND_AGAIN));
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
        foreach (EmailReplies::warnings($fields) as $warning) {
            $fix = $warning->getFix();
            $fixKey = $fix === null ? 0 : "s$fix";
            if (!isset($this->warningKeys[$warning->getCode()][$warning->getMessage()][$fixKey])) {
                $this->warningKeys[$warning->getCode()][$warning->getMessage()][$fixKey] = true;
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
        if ($this->mayHaveQueued || in_array($answer->getErrorCode(), self::UNCONFIRMED_CODES, true)) {
            $this->set($index, 'unconfirmed', $messageId, error: self::unconfirmedBy($answer, self::SEND_AGAIN));
            return;
        }
        $this->set($index, 'failed', $messageId, error: $answer);
    }

    public function result(): SendBatchResult
    {
        return new SendBatchResult($this->outcome(), $this->warnings);
    }

    public function interrupted(EmailError $error): EmailBatchError
    {
        if (!$this->mayHaveQueued) {
            $this->lost($error);
        }

        return $this->failure($error);
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
    private function requestError(array $fields, int $status, array $headers): EmailError
    {
        $answered = array_filter($fields, static fn($value): bool => $value !== null);
        if ($status === 404 && self::namesNoField($answered)) {
            $cause = $this->isTemplateForm
                ? "PostHog found no template with this template_id, or batch sends aren't available for this "
                    . 'project, so it'
                : "Batch sends or sending from code aren't available for this project, so PostHog";
            $answered = [
                ...array_diff_key($answered, ['why' => true]),
                'code' => 'not_found',
                'detail' => "$cause sent no email of this request.",
                'fix' => $this->isTemplateForm
                    ? "Check template_id. If it's right, " . self::SEND_EACH_ON_ITS_OWN
                    : 'If the project can send email from code, ' . self::SEND_EACH_ON_ITS_OWN,
            ];
        }
        if ($status === 413) {
            $answered['code'] = self::code($answered['code'] ?? null) ?? 'content_too_large';
            $answered['detail'] = EmailReplies::text($answered['detail'] ?? null)
                ?? "The batch is larger than PostHog takes in one request, so PostHog didn't send it.";
            $answered['fix'] = EmailReplies::text($answered['fix'] ?? null)
                ?? 'Split the recipients into smaller batches, or make the email or its template_data smaller.';
        }
        if (($answered['code'] ?? null) === self::BATCH_UNAVAILABLE) {
            $answered['fix'] = 'Until batch sends are on for the project, ' . self::SEND_SINGLY;
        }
        $code = EmailReplies::text($answered['code'] ?? null) ?? 'api_error';
        $detail = match (true) {
            $status >= 500, $code === self::IN_FLIGHT && $status === 409 => null,
            $status === 409 && $code !== self::BATCH_UNAVAILABLE => "PostHog answered the batch with a 409 ($code) "
                . "that doesn't say whether it queued the emails, so some of them may have gone out: read each "
                . "recipient's result.",
            $this->mayHaveQueued => "PostHog refused a retry of this batch with $code. An earlier attempt got no "
                . "answer, so some of its emails may have gone out: read each recipient's result.",
            default => null,
        };
        if ($detail !== null) {
            $answered = [
                ...array_diff_key($answered, ['why' => true]),
                'detail' => $detail,
                'fix' => self::RESEND_EACH,
            ];
        }

        return EmailReplies::error($answered, $status, $headers);
    }

    /**
     * @param array<string, mixed> $answered
     */
    private static function namesNoField(array $answered): bool
    {
        $code = EmailReplies::text($answered['code'] ?? null);

        return EmailReplies::text($answered['attr'] ?? null) === null
            && in_array($code, [null, 'not_found', 'api_error'], true);
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
        $code = self::code($error['code'] ?? null) ?? 'api_error';
        $status = $error['status'] ?? null;

        return new EmailError(
            $code,
            EmailReplies::text($error['detail'] ?? null) ?? "PostHog didn't queue this email: $code.",
            is_int($status) ? $status : null,
            messageId: $messageId
        );
    }

    private static function code(mixed $value): ?string
    {
        $code = EmailReplies::text($value);

        return $code === null || trim($code) === '' ? null : $code;
    }

    private static function unconfirmed(?string $messageId): EmailError
    {
        return new EmailError(
            'delivery_unavailable',
            "PostHog didn't confirm this email, so it may still go out. " . self::SEND_AGAIN,
            messageId: $messageId
        );
    }

    private static function withFix(EmailError $error, string $fix): EmailError
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

    private static function unconfirmedBy(EmailError $answer, string $fix): EmailError
    {
        $code = $answer->getErrorCode();

        return new EmailError(
            $code,
            "PostHog didn't confirm this email: $code, so it may still go out.",
            $answer->getStatusCode(),
            fix: $fix,
            docsUrl: $answer->getDocsUrl(),
            field: $answer->getField(),
            retryAfterMs: $answer->getRetryAfterMs(),
            previous: $answer,
            messageId: $answer->getMessageId()
        );
    }
}
