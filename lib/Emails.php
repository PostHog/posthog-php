<?php

namespace PostHog;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Sends transactional email, from an email template made in PostHog or from content your code rendered,
 * and reads the status of each email it sent.
 *
 * Get it from the client with `$client->emails()` or `PostHog::emails()`. The client needs a project
 * secret API key (`phs_...`) as `$secretKey`: sending needs the `hog_flow:write` scope, reading a status
 * needs `hog_flow:read`. A client on PostHog Cloud sends to its region's app host. For any other `host`,
 * such as a reverse proxy, set the `app_host` option, because the proxy must not receive the secret key.
 *
 * Each request times out after `email_request_timeout_ms` (15000 by default). Network errors, rate
 * limits, an in-flight idempotency key and an unavailable service are retried up to
 * `email_request_max_retries` times (2 by default) with the same idempotency key, waiting about 1 and
 * then 2 seconds, or the server's `Retry-After` when it is longer. A `Retry-After` over 30 seconds fails
 * at once. A send that is not queued throws EmailError.
 */
final class Emails
{
    public const DEFAULT_REQUEST_TIMEOUT_MS = 15000;
    public const DEFAULT_MAX_RETRIES = 2;

    private const SEND_PATH = '/api/projects/@current/messaging/send/email/';
    private const STATUS_PATH = '/api/projects/@current/messaging/emails/%s/';
    private const US_APP_HOST = 'https://us.posthog.com';
    private const EU_APP_HOST = 'https://eu.posthog.com';
    private const CLOUD_APP_HOSTS = [
        'us.i.posthog.com' => self::US_APP_HOST,
        'us.posthog.com' => self::US_APP_HOST,
        'app.posthog.com' => self::US_APP_HOST,
        'eu.i.posthog.com' => self::EU_APP_HOST,
        'eu.posthog.com' => self::EU_APP_HOST,
    ];
    private const MAX_RETRY_DELAY_MS = 30000;
    private const MAX_RESPONSE_BYTES = 1_048_576;
    private const MAX_RETRY_AFTER_SECONDS = 31_536_000;
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';
    private const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7e]{1,255}$/D';
    private const TEMPLATE_KEY_PATTERN = '/^[a-zA-Z0-9_-]{1,400}$/D';
    private const HEADER_TOKEN_PATTERN = '/^[\x21-\x7e]+$/D';
    private const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/D';

    private const SHARED_FIELDS = ['to', 'distinct_id', 'idempotency_key', 'max_retries'];
    private const TEMPLATE_FIELDS = ['template_id', 'template_data'];
    private const CODE_FIELDS = ['template_key', 'subject', 'html', 'text', 'from', 'reply_to', 'capture_engagement'];
    private const TEMPLATE_FORM_WHY = "A template made in PostHog sets its own sender, subject and content, "
        . "and follows the project's engagement setting.";
    private const CODE_FORM_WHY = 'A send with template_key takes its content from your code.';

    private const SEND_NOT_DONE = 'no email was sent';
    private const GET_NOT_DONE = 'no email status was read';
    private const SEND_KEY_NEEDED = 'Sending email needs a secret key, a project secret API key with the '
        . 'hog_flow:write scope.';
    private const GET_KEY_NEEDED = 'Reading an email status needs a secret key, a project secret API key with the '
        . 'hog_flow:read or hog_flow:write scope.';

    private ?string $secretKey;
    private ?string $appHost;
    private int $requestTimeoutMs;
    private int $maxRetries;
    private ?string $migratedFrom;
    private string $userAgent;
    private Closure $isDisabled;
    private Closure $isClosed;
    private Closure $transport;

    /**
     * @internal Use Client::emails() or PostHog::emails().
     *
     * @param string|null $secretKey A project secret API key.
     * @param string $host The client's host. Used only when it is PostHog Cloud.
     * @param string|null $appHost The PostHog app host, for a host that is not PostHog Cloud.
     * @param string|null $userAgent The User-Agent. Defaults to `posthog-php/<version>`.
     * @param Closure|null $transport `fn(string $method, string $url, list<string> $headers, ?string $body,
     *     int $timeoutMs): array{int, string, list<string>}` returning the status, body and header lines.
     *     It throws EmailError with the code `network_error` when no answer arrived.
     */
    public function __construct(
        ?string $secretKey,
        string $host,
        ?string $appHost = null,
        int $requestTimeoutMs = self::DEFAULT_REQUEST_TIMEOUT_MS,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        ?string $migratedFrom = null,
        ?string $userAgent = null,
        ?Closure $isDisabled = null,
        ?Closure $isClosed = null,
        ?Closure $transport = null
    ) {
        if ($requestTimeoutMs <= 0) {
            throw new InvalidArgumentException('email_request_timeout_ms must be a positive number of milliseconds.');
        }
        if ($maxRetries < 0) {
            throw new InvalidArgumentException('email_request_max_retries must be a nonnegative integer.');
        }
        if ($migratedFrom !== null && preg_match(self::HEADER_TOKEN_PATTERN, $migratedFrom) !== 1) {
            throw new InvalidArgumentException("migrated_from must be visible ASCII characters, such as 'resend'.");
        }
        $this->secretKey = StringNormalizer::normalizeOptional($secretKey);
        $this->appHost = self::resolveAppHost($host, $appHost);
        $this->requestTimeoutMs = $requestTimeoutMs;
        $this->maxRetries = $maxRetries;
        $this->migratedFrom = $migratedFrom;
        $this->userAgent = $userAgent ?? PostHog::LIBRARY . '/' . PostHog::VERSION;
        $this->isDisabled = $isDisabled ?? static fn(): bool => false;
        $this->isClosed = $isClosed ?? static fn(): bool => false;
        $this->transport = $transport ?? Closure::fromCallable([self::class, 'curlTransport']);
    }

    /**
     * Queue one email, from a template made in PostHog or from content your code rendered.
     *
     * Pass exactly one form:
     *
     * - A template made transactional in PostHog: `template_id`, its UUID, and optional `template_data`,
     *   the values for its `{{ variables.* }}` placeholders.
     * - Content your code rendered, named by a key: `template_key` (letters, digits, `-` and `_`, at most
     *   400), `subject`, and `html`, `text` or both. PostHog derives a missing `text` from the `html`, and
     *   sends the content as is without storing it. To send a React email, render it to HTML in your app
     *   first. This form also takes `from` (a verified sender, such as `Acme <hello@example.com>`),
     *   `reply_to` (one address or a list) and `capture_engagement` (default true; a project whose admin
     *   turned engagement capture off captures nothing, whatever the send asks).
     *
     * Both forms take `to` (one address), `distinct_id` (the recipient's distinct ID in your product;
     * without it, the email's events go to a person whose distinct ID is the address), `idempotency_key`
     * and `max_retries`. A null value counts as left out.
     *
     * A send with an idempotency key PostHog already accepted queues nothing and returns the first send's
     * result. Without a key, the client makes a UUID that covers its own retries. Pass a key derived from
     * what the email is for, such as `welcome:user_123`, to cover retries outside this call too, and pass
     * `max_retries` 0 when a job runner already retries the send.
     *
     * ```php
     * $client->emails()->send([
     *     'to' => 'user@example.com',
     *     'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b',
     *     'template_data' => ['name' => 'Ada'],
     *     'distinct_id' => 'user_123',
     *     'idempotency_key' => 'welcome:user_123',
     * ]);
     *
     * $client->emails()->send([
     *     'to' => 'user@example.com',
     *     'template_key' => 'password-reset',
     *     'subject' => 'Reset your password',
     *     'html' => $renderedHtml,
     *     'distinct_id' => 'user_123',
     * ]);
     * ```
     *
     * @param array{
     *     to: string,
     *     distinct_id?: string|null,
     *     idempotency_key?: string|null,
     *     max_retries?: int|null,
     *     template_id?: string,
     *     template_data?: array<string, mixed>|null,
     *     template_key?: string,
     *     subject?: string,
     *     html?: string|null,
     *     text?: string|null,
     *     from?: string|null,
     *     reply_to?: string|list<string>|null,
     *     capture_engagement?: bool|null
     * } $email
     * @return SendEmailResult The queued email. It means PostHog queued the email, not that it was delivered.
     * @throws EmailError PostHog did not queue the email, or the client could not tell whether it did. A
     *     failed argument check has the name `validation_error` and an `invalid_*` code, and sends nothing.
     */
    public function send(array $email): SendEmailResult
    {
        [$body, $idempotencyKey, $maxRetries] = $this->prepareSend($email);
        [$appHost, $secretKey] = $this->connect(self::SEND_NOT_DONE, self::SEND_KEY_NEEDED);
        $headers = $this->headers($secretKey, [
            'Content-Type: application/json',
            'Idempotency-Key: ' . $idempotencyKey,
        ]);
        $result = $this->withRetries($maxRetries, function () use ($appHost, $headers, $body): SendEmailResult {
            return self::parseSendReply(...$this->request('POST', $appHost . self::SEND_PATH, $headers, $body));
        });
        foreach ($result->getWarnings() as $warning) {
            // The fields come from the reply, so control characters are escaped to keep each warning on one log line.
            $line = $warning->getCode() . ': ' . $warning->getMessage();
            error_log('[PostHog][Emails] Email warning ' . addcslashes($line, "\0..\37\177\\"));
        }

        return $result;
    }

    /**
     * Read what happened to an email that send() queued, for 30 days after the send.
     *
     * ```php
     * $status = $client->emails()->get($result->getId());
     * if ($status->getStatus() === 'bounced') {
     *     echo $status->getReason();
     * }
     * ```
     *
     * @param string $messageId The ID of the SendEmailResult that send() returned.
     * @throws EmailError PostHog did not answer with a status. The code is `not_found` for an unknown ID,
     *     an email older than 30 days, or for a few seconds after the send.
     */
    public function get(string $messageId): EmailStatus
    {
        if (preg_match(self::UUID_PATTERN, $messageId) !== 1) {
            throw new EmailError(
                'invalid_message_id',
                'The message ID must be the ID that send() returned.',
                field: 'message_id'
            );
        }
        [$appHost, $secretKey] = $this->connect(self::GET_NOT_DONE, self::GET_KEY_NEEDED);
        $url = $appHost . sprintf(self::STATUS_PATH, strtolower($messageId));
        $headers = $this->headers($secretKey, []);

        return $this->withRetries($this->maxRetries, function () use ($url, $headers): EmailStatus {
            return self::parseStatusReply(...$this->request('GET', $url, $headers, null));
        });
    }

    /**
     * @return array{string, string, int} The JSON body, the idempotency key and the retry limit.
     */
    private function prepareSend(array $email): array
    {
        $email = array_filter($email, static fn($value): bool => $value !== null);
        $to = $email['to'] ?? null;
        if (!is_string($to) || trim($to) === '') {
            throw self::invalid('invalid_recipient', 'to must be one email address as a nonempty string.', 'to');
        }
        $distinctId = $email['distinct_id'] ?? null;
        if ($distinctId !== null && (!is_string($distinctId) || $distinctId === '')) {
            throw self::invalid('invalid_distinct_id', 'distinct_id must be a nonempty string.', 'distinct_id');
        }
        $hasTemplateId = array_key_exists('template_id', $email);
        if ($hasTemplateId === array_key_exists('template_key', $email)) {
            throw new EmailError(
                'invalid_form',
                'Send either template_id, or template_key with subject and html or text, so no email was sent.'
            );
        }
        $payload = $hasTemplateId ? self::templateContent($email) : self::codeContent($email);
        $idempotencyKey = $email['idempotency_key'] ?? null;
        if (
            $idempotencyKey !== null
            && (!is_string($idempotencyKey) || preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1)
        ) {
            throw self::invalid(
                'invalid_idempotency_key',
                'idempotency_key must be 1 to 255 visible ASCII characters without spaces.',
                'idempotency_key'
            );
        }
        $maxRetries = $email['max_retries'] ?? $this->maxRetries;
        if (!is_int($maxRetries) || $maxRetries < 0) {
            throw self::invalid('invalid_max_retries', 'max_retries must be a nonnegative integer.', 'max_retries');
        }
        $payload['to'] = $to;
        if ($distinctId !== null) {
            $payload['distinct_id'] = $distinctId;
        }
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new EmailError(
                'invalid_form',
                'The email could not be encoded as JSON, so no email was sent: ' . $exception->getMessage() . '.',
                previous: $exception
            );
        }

        return [$body, $idempotencyKey ?? Uuid::v4(), $maxRetries];
    }

    /**
     * @return array<string, mixed>
     */
    private static function templateContent(array $email): array
    {
        $templateId = $email['template_id'];
        if (!is_string($templateId) || preg_match(self::UUID_PATTERN, $templateId) !== 1) {
            throw self::invalid('invalid_template_id', "template_id must be the template's UUID.", 'template_id');
        }
        $templateData = $email['template_data'] ?? [];
        if (!is_array($templateData) || ($templateData !== [] && array_is_list($templateData))) {
            throw self::invalid(
                'invalid_template_data',
                'template_data must be an array with a value per variable name.',
                'template_data'
            );
        }
        self::refuseFieldsOutside($email, true);

        return [
            'template_id' => strtolower($templateId),
            'message_data' => $templateData === [] ? (object) [] : $templateData,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function codeContent(array $email): array
    {
        $templateKey = $email['template_key'];
        if (!is_string($templateKey) || preg_match(self::TEMPLATE_KEY_PATTERN, $templateKey) !== 1) {
            throw self::invalid(
                'invalid_template_key',
                'template_key can only have letters, digits, hyphens (-) and underscores (_), at most 400.',
                'template_key'
            );
        }
        self::refuseFieldsOutside($email, false);
        $subject = $email['subject'] ?? null;
        if (!is_string($subject) || $subject === '') {
            throw self::invalid('invalid_form', 'A send with template_key needs a nonempty subject.', 'subject');
        }
        $content = ['template_key' => $templateKey, 'subject' => $subject];
        foreach (['html', 'text'] as $field) {
            if (!array_key_exists($field, $email)) {
                continue;
            }
            if (!is_string($email[$field])) {
                throw self::invalid('invalid_form', "$field must be a string.", $field);
            }
            $content[$field] = $email[$field];
        }
        if (($content['html'] ?? '') === '' && ($content['text'] ?? '') === '') {
            throw self::invalid(
                'invalid_form',
                'A send with template_key needs a nonempty html, text or both.',
                array_key_exists('html', $content) || !array_key_exists('text', $content) ? 'html' : 'text'
            );
        }
        if (array_key_exists('from', $email)) {
            if (!is_string($email['from']) || trim($email['from']) === '') {
                throw self::invalid('invalid_form', 'from must be a nonempty string.', 'from');
            }
            $content['from'] = $email['from'];
        }
        if (array_key_exists('reply_to', $email)) {
            $content['reply_to'] = self::addresses($email['reply_to']);
        }
        $captureEngagement = $email['capture_engagement'] ?? true;
        if (!is_bool($captureEngagement)) {
            throw self::invalid('invalid_form', 'capture_engagement must be true or false.', 'capture_engagement');
        }
        $content['capture_engagement'] = $captureEngagement;

        return $content;
    }

    /**
     * @return list<string>
     */
    private static function addresses(mixed $value): array
    {
        $addresses = is_string($value) ? [$value] : $value;
        if (!is_array($addresses) || !array_is_list($addresses) || $addresses === []) {
            throw self::invalid('invalid_form', 'reply_to must be one address or a list of addresses.', 'reply_to');
        }
        foreach ($addresses as $address) {
            if (!is_string($address) || trim($address) === '') {
                throw self::invalid('invalid_form', 'reply_to must not hold a blank address.', 'reply_to');
            }
        }

        return $addresses;
    }

    private static function refuseFieldsOutside(array $email, bool $isTemplateForm): void
    {
        $formFields = array_merge(self::SHARED_FIELDS, $isTemplateForm ? self::TEMPLATE_FIELDS : self::CODE_FIELDS);
        $otherFields = $isTemplateForm ? self::CODE_FIELDS : self::TEMPLATE_FIELDS;
        foreach (array_keys($email) as $field) {
            if (in_array($field, $formFields, true)) {
                continue;
            }
            $field = (string) $field;
            $formName = $isTemplateForm ? 'template_id' : 'template_key';
            $message = "A send with $formName doesn't take $field, so no email was sent.";
            if (!in_array($field, $otherFields, true)) {
                throw new EmailError('invalid_form', $message, field: $field, fix: "Remove $field and send again.");
            }
            throw new EmailError(
                'invalid_form',
                $message,
                why: $isTemplateForm ? self::TEMPLATE_FORM_WHY : self::CODE_FORM_WHY,
                fix: "Remove $field, or " . ($isTemplateForm
                    ? 'send content from your code with template_key.'
                    : 'send a template made in PostHog with template_id.'),
                field: $field
            );
        }
    }

    private static function invalid(string $code, string $message, string $field): EmailError
    {
        return new EmailError($code, $message, field: $field);
    }

    /**
     * @return array{string, string} The app host and the secret key.
     */
    private function connect(string $notDone, string $keyNeeded): array
    {
        if (($this->isDisabled)()) {
            throw new EmailError(
                'client_disabled',
                "The PostHog client is disabled because it has no project API key, so $notDone."
            );
        }
        if (($this->isClosed)()) {
            throw new EmailError('client_closed', "The PostHog client is shut down, so $notDone.");
        }
        if ($this->appHost === null) {
            // A custom host is often an ingestion proxy, which must not receive the secret key.
            throw new EmailError(
                'missing_app_host',
                "The client's host is not PostHog Cloud, so the client can't tell where to send email. "
                . 'Set the app_host option to your PostHog app host.'
            );
        }
        if ($this->secretKey === null) {
            throw new EmailError('missing_secret_key', $keyNeeded);
        }

        return [$this->appHost, $this->secretKey];
    }

    /**
     * @param list<string> $extra
     * @return list<string>
     */
    private function headers(string $secretKey, array $extra): array
    {
        $headers = [
            'Authorization: Bearer ' . $secretKey,
            'Accept: application/json',
            'User-Agent: ' . $this->userAgent,
            ...$extra,
        ];
        if ($this->migratedFrom !== null) {
            $headers[] = 'X-PostHog-Migrated-From: ' . $this->migratedFrom;
        }

        return $headers;
    }

    /**
     * @template T
     * @param Closure(): T $attemptOnce
     * @return T
     */
    private function withRetries(int $maxRetries, Closure $attemptOnce): mixed
    {
        for ($attempt = 0;; $attempt++) {
            try {
                return $attemptOnce();
            } catch (EmailError $error) {
                $delayMs = self::retryDelayMs($error, $attempt, $maxRetries);
                if ($delayMs === null) {
                    throw $error;
                }
                $this->waitToRetry($delayMs, $error);
            }
        }
    }

    /**
     * A handled signal cuts a sleep short, so this sleeps again until the retry is due. A signal handler can also
     * shut the client down, during the request or the wait; a closed client neither waits nor sends, so the
     * operation settles with the failure it already has.
     */
    private function waitToRetry(int $delayMs, EmailError $error): void
    {
        $clock = Clock::get();
        if ($clock instanceof NativeClock) {
            // The wall clock can jump during the wait, so the deadline is kept on a clock that only moves forward.
            $clock = new MonotonicClock();
        }
        // Measured from the start of the wait: microseconds since the epoch don't fit in an int on 32-bit PHP.
        $start = $clock->now();
        while (!($this->isClosed)()) {
            $remainingUs = $delayMs * 1000 - self::microsecondsBetween($start, $clock->now());
            if ($remainingUs <= 0) {
                return;
            }
            $clock->sleep($remainingUs / 1_000_000);
        }

        throw $error;
    }

    private static function microsecondsBetween(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        // Read as floats: on 32-bit PHP an epoch after 2038 doesn't fit in an int.
        $seconds = (int) ((float) $end->format('U') - (float) $start->format('U'));

        return $seconds * 1_000_000 + (int) $end->format('u') - (int) $start->format('u');
    }

    private static function isRetryable(EmailError $error): bool
    {
        // A send carries an idempotency key, so a retry can't queue a second email. A status read changes nothing.
        $status = $error->getStatusCode();
        if ($status === null) {
            return $error->getErrorCode() === 'network_error';
        }
        if ($status === 409) {
            return $error->getErrorCode() === 'idempotency_key_in_flight';
        }
        if ($status === 429) {
            // Waiting doesn't fix a monthly quota.
            return $error->getErrorCode() !== 'quota_limited';
        }

        return $status === 503;
    }

    private static function retryDelayMs(EmailError $error, int $attempt, int $maxRetries): ?int
    {
        if ($attempt >= $maxRetries || !self::isRetryable($error)) {
            return null;
        }
        $retryAfterMs = $error->getRetryAfterMs();
        // Retrying before a longer Retry-After would break the server's rate limit.
        if ($retryAfterMs !== null && $retryAfterMs > self::MAX_RETRY_DELAY_MS) {
            return null;
        }
        $backoffMs = (int) min(self::MAX_RETRY_DELAY_MS, 1000 * (2 ** $attempt) * random_int(800, 1200) / 1000);

        return max($backoffMs, $retryAfterMs ?? 0);
    }

    /**
     * @param list<string> $headers
     * @return array{int, string, list<string>}
     */
    private function request(string $method, string $url, array $headers, ?string $body): array
    {
        return ($this->transport)($method, $url, $headers, $body, $this->requestTimeoutMs);
    }

    /**
     * @param list<string> $headers
     * @return array{int, string, list<string>}
     */
    private static function curlTransport(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutMs
    ): array {
        if (!function_exists('curl_init')) {
            throw new EmailError(
                'missing_curl',
                'Sending email and reading its status need the PHP curl extension, which is not loaded.',
                fix: 'Install or enable ext-curl.'
            );
        }
        $responseHeaders = [];
        $responseBody = '';
        $tooLarge = false;
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            // An empty Expect stops cURL from waiting for 100-continue before it sends a large body.
            CURLOPT_HTTPHEADER => [...$headers, 'Expect:'],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$tooLarge): int {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                $responseHeaders[] = trim($header);
                return strlen($header);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $succeeded = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($tooLarge) {
            $limit = self::MAX_RESPONSE_BYTES;
            throw new EmailError(
                'response_too_large',
                "PostHog answered with more than $limit bytes, so the SDK stopped reading."
            );
        }
        if ($succeeded === false || curl_errno($curl) !== 0 || $status === 0) {
            throw new EmailError('network_error', 'The request to PostHog failed: ' . curl_error($curl));
        }

        return [$status, $responseBody, $responseHeaders];
    }

    /**
     * @param list<string> $headers
     */
    private static function parseSendReply(int $status, string $body, array $headers): SendEmailResult
    {
        $fields = self::replyFields($body);
        if ($status < 200 || $status >= 300) {
            throw self::replyError($fields, $status, $headers);
        }
        $id = self::text($fields['message_id'] ?? null);
        if ($id === null) {
            throw new EmailError('api_error', 'PostHog answered without a message ID.', $status);
        }
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

        return new SendEmailResult($id, ($fields['replayed'] ?? false) === true, $warnings);
    }

    /**
     * @param list<string> $headers
     */
    private static function parseStatusReply(int $status, string $body, array $headers): EmailStatus
    {
        $fields = self::replyFields($body);
        if ($status < 200 || $status >= 300) {
            throw self::replyError($fields, $status, $headers);
        }
        $id = self::text($fields['id'] ?? null);
        $deliveryStatus = self::text($fields['status'] ?? null);
        $createdAt = self::timestamp($fields['created_at'] ?? null);
        $statusAt = self::timestamp($fields['status_at'] ?? null);
        if ($id === null || $deliveryStatus === null || $createdAt === null || $statusAt === null) {
            throw new EmailError('api_error', 'PostHog answered without an email status.', $status);
        }

        return new EmailStatus($id, $deliveryStatus, $createdAt, $statusAt, self::text($fields['reason'] ?? null));
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $headers
     */
    private static function replyError(array $fields, int $status, array $headers): EmailError
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
     * @return array<string, mixed>
     */
    private static function replyFields(string $body): array
    {
        $payload = json_decode($body, true);
        return is_array($payload) && !array_is_list($payload) ? $payload : [];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        $text = self::text($value);
        if ($text === null || preg_match(self::TIMESTAMP_PATTERN, $text) !== 1) {
            return null;
        }
        try {
            return new DateTimeImmutable($text);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param list<string> $headers
     */
    private static function retryAfterMs(array $headers): ?int
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
            // The format's GMT is literal text, so without a zone PHP would read the time in its default zone.
            $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $value, new DateTimeZone('UTC'));
            if ($date === false) {
                return null;
            }
            // getTimestamp() throws on 32-bit PHP for a date after 2038, so the epoch is read as a float.
            $seconds = (float) $date->format('U') - (float) Clock::get()->now()->format('U.u');
        }
        if (!is_finite($seconds)) {
            return null;
        }
        // Clamped in seconds first: milliseconds since the epoch, or a huge value times 1000, overflow an int,
        // on 32-bit PHP soonest.
        $maxSeconds = min(self::MAX_RETRY_AFTER_SECONDS, intdiv(PHP_INT_MAX, 1000));

        return (int) (min(max(0.0, $seconds), $maxSeconds) * 1000);
    }

    /**
     * The app host for a send, or null when the client can't tell it. PostHog Cloud ingestion hosts don't
     * serve the app API, so they map to their region's app host.
     */
    private static function resolveAppHost(string $host, ?string $appHost): ?string
    {
        $appHost = StringNormalizer::normalizeOptional($appHost);
        if ($appHost !== null) {
            $appHost = rtrim($appHost, '/');
            $bare = preg_replace('#^https?://#i', '', $appHost);
            if (isset(self::CLOUD_APP_HOSTS[strtolower($bare)])) {
                return self::CLOUD_APP_HOSTS[strtolower($bare)];
            }
            return $bare === $appHost ? 'https://' . $appHost : $appHost;
        }
        $bare = strtolower(rtrim(preg_replace('#^https?://#i', '', trim($host)), '/'));

        return self::CLOUD_APP_HOSTS[$bare] ?? null;
    }
}
