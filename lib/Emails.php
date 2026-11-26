<?php

namespace PostHog;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use PostHog\Internal\EmailBatch;
use PostHog\Internal\EmailRecipient;
use PostHog\Internal\EmailReplies;
use PostHog\Internal\ScheduledEmailResult;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Clock\NativeClock;
use Throwable;

/**
 * Sends transactional email, from an email template made in PostHog or from content your code rendered,
 * to one recipient with send() or to many with batch(), and reads the status of each email it sent.
 *
 * Get it from the client with `$client->emails()` or `PostHog::emails()`. The client needs a project
 * secret API key (`phs_...`) as `$secretKey`: sending needs the `transactional_email:write` scope, reading
 * a status needs `transactional_email:read` or `transactional_email:write`. Keys made with `hog_flow:write`
 * still work. A client on PostHog Cloud sends to its region's app host. For any other `host`,
 * such as a reverse proxy, set the `app_host` option, because the proxy must not receive the secret key.
 *
 * Each request times out after `email_request_timeout_ms` (15000 by default). Network errors, rate
 * limits, an in-flight idempotency key and an unavailable service are retried up to
 * `email_request_max_retries` times (2 by default) with the same idempotency key, waiting about 1 and
 * then 2 seconds, or the server's `Retry-After` when it is longer. A `Retry-After` over 30 seconds fails
 * at once. A send that is not queued or scheduled throws EmailError, and so does a scheduled send whose time
 * PostHog didn't confirm.
 *
 * A send with attachments first uploads each file PostHog doesn't have yet to the storage URL PostHog
 * answers, which is on another host than the app host. It uploads only to an `https` URL, unless the app
 * host is plain `http://` for local development. Each upload gets the request timeout plus 1 ms
 * for every 100 bytes of the file, but only the request timeout to connect. A storage 429, 500, 502, 503
 * or 504 is retried as above.
 *
 * @phpstan-type Upload array{filename: string, size: int, content: string, field: string}
 */
final class Emails
{
    public const DEFAULT_REQUEST_TIMEOUT_MS = 15000;
    public const DEFAULT_MAX_RETRIES = 2;

    private const SEND_PATH = '/api/messaging/send/email/';
    private const UPLOADS_PATH = '/api/messaging/attachments/uploads/';
    private const STATUS_PATH = '/api/messaging/emails/%s/';
    private const BATCH_PATH = '/api/messaging/send/email_batch/';
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
    private const BATCH_REQUEST_TIMEOUT_MS = 45000;
    private const LOST_ANSWER_CODES = ['network_error', 'response_too_large'];
    private const FIELDS_A_BATCH_REFUSES = [
        'to' => 'Give each address as to in recipients.',
        'distinct_id' => "Give each recipient's distinct_id in recipients.",
        'idempotency_key' => "Give each recipient's idempotency_key in recipients.",
        'scheduled_at' => 'Send each email with emails()->send(), which takes scheduled_at.',
        'cc' => 'Send each email with emails()->send(), which takes cc.',
        'bcc' => 'Send each email with emails()->send(), which takes bcc.',
        'attachments' => 'Send each email with emails()->send(), which takes attachments.',
    ];
    private const SEND_NOW_LEEWAY_SECONDS = 60;
    private const MAX_RESPONSE_BYTES = 1_048_576;
    private const NO_EXPECT_100_CONTINUE = 'Expect:';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';
    private const TEMPLATE_KEY_PATTERN = '/^[a-zA-Z0-9_-]{1,400}$/D';
    private const HEADER_TOKEN_PATTERN = '/^[\x21-\x7e]+$/D';
    private const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/D';
    private const SCHEDULED_AT_PATTERN =
        '/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?(?:Z|([+-]\d{2}):(\d{2}))$/D';

    private const SHARED_FIELDS = ['to', 'distinct_id', 'idempotency_key', 'max_retries', 'scheduled_at'];
    private const TEMPLATE_FIELDS = ['template_id', 'template_data'];
    private const CODE_FIELDS = [
        'template_key',
        'subject',
        'html',
        'text',
        'from',
        'reply_to',
        'cc',
        'bcc',
        'attachments',
        'capture_engagement',
        'sensitive',
        'tracking',
    ];
    private const ATTACHMENT_FIELDS = ['filename', 'content', 'content_type', 'content_id'];
    private const MAX_ATTACHMENTS = 10;
    private const MAX_MESSAGE_BYTES = 40_000_000;
    private const MESSAGE_HEADERS_BYTES = 65_536;
    private const UPLOAD_BYTES_PER_EXTRA_MS = 100;
    private const MESSAGE_LIMIT = '40 MB after Base64 encoding, about 29 MB of files';
    private const HEADER_NAME_PATTERN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D";
    private const TEMPLATE_FORM_WHY = "A template made in PostHog sets its own sender, subject, content, tracking "
        . "and sensitive setting, and follows the project's engagement setting.";
    private const CODE_FORM_WHY = 'A send with template_key takes its content from your code.';

    private const SEND_NOT_DONE = 'no email was sent';
    private const GET_NOT_DONE = 'no email status was read';
    private const SEND_KEY_NEEDED = 'Sending email needs a secret key, a project secret API key with the '
        . 'transactional_email:write scope.';
    private const GET_KEY_NEEDED = 'Reading an email status needs a secret key, a project secret API key with the '
        . 'transactional_email:read or transactional_email:write scope.';

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
     *     int $timeoutMs, int $connectTimeoutMs): array{int, string, list<string>}` returning the status, body
     *     and header lines.
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
        $migratedFrom = StringNormalizer::normalizeOptional($migratedFrom);
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
     *   `reply_to`, `cc` and `bcc` (one address or a list; at most 10 for `cc` and `bcc`), `attachments`,
     *   `sensitive` and `tracking`. An empty `reply_to`, `cc`, `bcc` or `attachments` list is left out.
     * - `attachments` is a list of at most 10 files, and the email can be at most 40 MB after Base64 encoding, which
     *   leaves about 29 MB for the files. The 40 MB counts the subject and the bodies, and the `html` twice without
     *   `text`, since PostHog derives the text from it. Each file is an array with `filename`, `content` (the file's
     *   bytes, such as `file_get_contents()` returns; pass `base64_decode($content)` when you hold Base64, such as from
     *   a Resend payload), and optional `content_type` and `content_id`. Reference a `content_id` of `logo` in the HTML
     *   as `<img src="cid:logo">` to show the file inline. PostHog can't fetch a file from a path or URL. The client
     *   checks both limits before any request, hashes each file with SHA-256, uploads the files PostHog doesn't have
     *   yet, and then sends the email with each file's hash. A file that fails to upload throws
     *   `attachment_upload_failed` with the storage's answer, and no email is sent. An upload never follows a
     *   redirect: storage that answers one fails that file the same way. A PostHog without the upload route, or a
     *   project that can't send email from code, answers `not_found` on `attachments`, and no email is sent.
     *
     * `sensitive` marks the email as sensitive: set it to true when the email carries login links, reset
     * tokens or codes. A sensitive email keeps its links unchanged unless `tracking` turns clicks on,
     * leaves its subject off engagement events, and keeps its content out of logs. Left out, the email
     * takes the setting of the template made in PostHog whose key matches `template_key`, and is
     * sensitive when no template matches.
     *
     * `tracking` says what PostHog tracks for the email. Left out, PostHog counts opens and captures
     * engagement events, and tracks clicks only when the email isn't sensitive. `true` turns on opens,
     * clicks and engagement events, even for a sensitive email. `false` turns them all off: the email
     * has no open pixel and no rewritten links, and PostHog captures no engagement events for it. An
     * array such as `['clicks' => true]` sets `opens` (default true), `clicks` (default: on unless the
     * email is sensitive) and `engagement` (default true) one by one. Turn clicks on for a sensitive
     * email only when its links are safe to rewrite. Click tracking needs open tracking. A project whose
     * admin turned engagement capture off captures nothing, whatever the send says.
     *
     * The client sends `sensitive` and `tracking` only when you set them, and they need a PostHog
     * version that takes them.
     *
     * `capture_engagement` is deprecated: use `tracking` with `engagement` instead. It still works, but a
     * send can't set both. The client sends `capture_engagement` only when you set it.
     *
     * A PostHog version that doesn't take a field yet, such as `cc`, refuses the send with an error that
     * names the field. It never drops the field and sends the rest.
     *
     * Both forms take `to` (one address), `distinct_id` (the recipient's distinct ID in your product;
     * without it, the email's events go to a person whose distinct ID is the address), `idempotency_key`,
     * `max_retries` and `scheduled_at`. A null value counts as left out.
     *
     * `scheduled_at` sends the email later, at most 7 days ahead: a DateTimeInterface, or an ISO 8601 string
     * with an offset, such as `2026-12-01T09:00:00Z` or `2026-12-01T10:00:00+01:00`. A string without an
     * offset throws `invalid_scheduled_at` before any request. The client rounds a time finer than a
     * millisecond up to the next millisecond, so the email never goes out early, and sends that time in UTC
     * on every retry. A time that has passed sends the email now. Only PostHog Cloud schedules emails: on
     * any other app host, such as a self-hosted PostHog, a reverse proxy or a custom domain, a send with
     * `scheduled_at` throws `scheduling_requires_cloud` before any request. The result's getStatus() is
     * `scheduled` and getScheduledAt() the confirmed time, or `queued` and null when the time had passed.
     * When PostHog accepts the email without confirming the time, the send throws `schedule_not_confirmed`
     * with the email's getMessageId(): read its status before you send it again. So does a `queued` answer
     * for a time more than a minute ahead of this machine's clock when the send's first attempt went out,
     * even when a retry gets the answer; the minute allows for clock difference.
     * Work out a relative time, such as an hour from now, once, and pass the same time when you send again
     * with the same `idempotency_key`: the key covers the time, so another time throws
     * `idempotency_key_reused`. To stop
     * scheduled emails before they go out, disable the email's workflow in PostHog until they have reached
     * a final status. That refuses every new send of it meanwhile.
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
     * $result = $client->emails()->send([
     *     'to' => 'user@example.com',
     *     'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b',
     *     'idempotency_key' => 'trial-ending:user_123',
     *     'scheduled_at' => new DateTimeImmutable('+1 day'),
     * ]);
     * $result->getStatus(); // scheduled
     *
     * $client->emails()->send([
     *     'to' => 'user@example.com',
     *     'template_key' => 'password-reset',
     *     'subject' => 'Reset your password',
     *     'html' => $renderedHtml,
     *     'distinct_id' => 'user_123',
     * ]);
     *
     * $client->emails()->send([
     *     'to' => 'user@example.com',
     *     'template_key' => 'receipt',
     *     'subject' => 'Your receipt',
     *     'html' => $renderedHtml,
     *     'bcc' => 'finance@example.com',
     *     'attachments' => [
     *         ['filename' => 'receipt.pdf', 'content' => file_get_contents($receiptPath)],
     *     ],
     * ]);
     * ```
     *
     * @param array{
     *     to: string,
     *     distinct_id?: string|null,
     *     idempotency_key?: string|null,
     *     max_retries?: int|null,
     *     scheduled_at?: \DateTimeInterface|string|null,
     *     template_id?: string,
     *     template_data?: array<string, mixed>|null,
     *     template_key?: string,
     *     subject?: string,
     *     html?: string|null,
     *     text?: string|null,
     *     from?: string|null,
     *     reply_to?: string|list<string>|null,
     *     cc?: string|list<string>|null,
     *     bcc?: string|list<string>|null,
     *     attachments?: list<array{
     *         filename: string,
     *         content: string,
     *         content_type?: string|null,
     *         content_id?: string|null
     *     }>|null,
     *     sensitive?: bool|null,
     *     tracking?: bool|array{opens?: bool|null, clicks?: bool|null, engagement?: bool|null}|null,
     *     capture_engagement?: bool|null
     * } $email
     * @return SendEmailResult The accepted email. It means PostHog queued or scheduled the email, not that it
     *     was delivered.
     * @throws EmailError PostHog did not queue the email, the client could not tell whether it did, or, with
     *     `schedule_not_confirmed`, PostHog accepted it without confirming its `scheduled_at`. A
     *     failed argument check has the name `validation_error` and an `invalid_*` code, and sends nothing.
     *     An attachment the client can't send has the code `invalid_attachment` and a field such as
     *     `attachments__0__content`, and its message never repeats the file's content. More than 10 files
     *     throw `too_many_attachments`, and an email over 40 MB throws `message_too_large`, before any
     *     request.
     */
    public function send(array $email): SendEmailResult
    {
        [$body, $idempotencyKey, $maxRetries, $files, $scheduledAt] = $this->prepareSend($email);
        [$appHost, $secretKey] = $this->connect(self::SEND_NOT_DONE, self::SEND_KEY_NEEDED);
        if ($scheduledAt !== null) {
            self::refuseSchedulingOutsideCloud($appHost);
        }
        if ($files !== []) {
            $this->upload($appHost, $secretKey, $files, $maxRetries);
            $this->refuseWhenClosed(self::SEND_NOT_DONE);
        }
        $headers = $this->headers($secretKey, [
            'Content-Type: application/json',
            'Idempotency-Key: ' . $idempotencyKey,
        ]);
        $mayGoNow = $scheduledAt !== null && self::isAtMostAMinuteAhead($scheduledAt);
        $result = $this->withRetries(
            $maxRetries,
            function () use ($appHost, $headers, $body, $scheduledAt, $mayGoNow): SendEmailResult {
                $reply = $this->request('POST', $appHost . self::SEND_PATH, $headers, $body);

                return self::parseSendReply($scheduledAt, $mayGoNow, ...$reply);
            }
        );
        self::logWarnings($result->getWarnings());

        return $result;
    }

    /**
     * @param list<EmailWarning> $warnings
     */
    private static function logWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            $line = $warning->getCode() . ': ' . $warning->getMessage();
            try {
                error_log('[PostHog][Emails] Email warning ' . self::onOneLogLine($line));
            } catch (Throwable) {
                continue;
            }
        }
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
     * Queue the same email for many recipients, one email each, from a template made in PostHog or from content
     * your code rendered.
     *
     * Pass one form, as send() takes it:
     *
     * - A template made transactional in PostHog: `template_id` and optional `template_data`, shared by every
     *   recipient. A recipient's own `template_data` is merged over it: a top-level key set for the recipient
     *   replaces the same key of the batch.
     * - Content your code rendered, the same for every recipient: `template_key`, `subject`, and `html`, `text`
     *   or both, with optional `from`, `reply_to`, `sensitive`, `tracking` and `capture_engagement`. A recipient
     *   can't carry `template_data`: send personalized content with a `template_id` batch, or with send().
     *
     * A batch takes no `cc`, `bcc`, `attachments` or `scheduled_at`, and no `to`, `distinct_id` or
     * `idempotency_key` of its own: each throws `invalid_form` before any request.
     *
     * `recipients` is a list of 1 to 100 recipients, each an array with `to` and optional `distinct_id`,
     * `idempotency_key` and `template_data`. A null value counts as left out. The client sends the batch in one
     * request and waits 45 seconds for the answer, or `email_request_timeout_ms` when that is longer.
     * `max_retries` overrides the client's retries for the request.
     *
     * Each recipient's `idempotency_key` is 1 to 255 visible ASCII characters, a different one for each recipient
     * of the call, and every retry reuses it, so a retry never queues an email twice. Without it, the client makes
     * one that covers its own retries but not a second call, so pass keys you store when a batch may be cut off
     * and run again: a rerun within 24 hours of the first run replays the emails that went out instead of sending
     * them twice. Build each key from an ID you store with the job, such as the digest's own ID, never from the time
     * of the run, so a retry after midnight makes the same keys. A single send and a batch recipient
     * with the same key and the same email replay each other. Never reuse a key for another email: PostHog sends one
     * email per key, so the other doesn't go out. PostHog usually refuses it with `idempotency_key_reused`, but two
     * sends that arrive at the same time can both answer `queued`.
     *
     * Check each result's getStatus():
     *
     * - `queued`: PostHog accepted the email.
     * - `failed`: PostHog refused it, and getError() says why, such as a suppressed address.
     * - `unconfirmed`: the client can't tell whether PostHog accepted it, such as after a lost answer, or a 409
     *   other than `batch_unavailable`, as when another request still holds its key. Send it again with the same
     *   key within 24 hours of its first use, so it goes out once.
     *
     * When PostHog refuses the batch as a whole, such as a rate limit past its retries, batch() throws
     * EmailBatchError, whose getResults() holds every recipient: `failed`, or `unconfirmed` when an attempt may have
     * queued it. getFix() carries PostHog's advice. The code `content_too_large` with status 413 means the request
     * is larger than PostHog takes, also when a proxy answers 413 without a code: split the recipients into smaller
     * batches, or send a smaller email. With status 400 it means
     * the content or template_data every recipient shares is too large, so fewer recipients won't help: make it
     * smaller, or send those emails one at a time with send().
     * `batch_unavailable` (409) means the project can't send batches yet, and is never retried. Send each email the
     * batch didn't queue with send() and its recipient's `idempotency_key`.
     * `not_found` (404) without a field means PostHog found no template with that `template_id`, or batch sends
     * aren't available for the project. Check the `template_id`. If it's right, send each recipient on its own with
     * send() and its `idempotency_key` until batch sends are available.
     * When an earlier attempt got no answer, a refusal of the retry doesn't prove nothing went out: the error says
     * some emails may have gone out, every recipient stays `unconfirmed`, and getFix() says to send each one again
     * with the same `idempotency_key`.
     *
     * ```php
     * $result = $client->emails()->batch([
     *     'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b',
     *     'recipients' => [
     *         ['to' => 'ada@example.com', 'template_data' => ['name' => 'Ada'], 'idempotency_key' => "$digestId:ada"],
     *         [
     *             'to' => 'alan@example.com',
     *             'template_data' => ['name' => 'Alan'],
     *             'idempotency_key' => "$digestId:alan",
     *         ],
     *     ],
     * ]);
     * foreach ($result->getResults() as $email) {
     *     if ($email->getStatus() !== 'queued') {
     *         echo $email->getTo(), ': ', $email->getStatus(), ' ', $email->getError()?->getMessage(), PHP_EOL;
     *     }
     * }
     * ```
     *
     * @param array{
     *     recipients: list<array{
     *         to: string,
     *         distinct_id?: string|null,
     *         idempotency_key?: string|null,
     *         template_data?: array<string, mixed>|null
     *     }>,
     *     max_retries?: int|null,
     *     template_id?: string,
     *     template_data?: array<string, mixed>|null,
     *     template_key?: string,
     *     subject?: string,
     *     html?: string|null,
     *     text?: string|null,
     *     from?: string|null,
     *     reply_to?: string|list<string>|null,
     *     sensitive?: bool|null,
     *     tracking?: bool|array{opens?: bool|null, clicks?: bool|null, engagement?: bool|null}|null,
     *     capture_engagement?: bool|null
     * } $batch
     * @return SendBatchResult A result per recipient, in the order of `recipients`.
     * @throws EmailBatchError PostHog refused the batch as a whole, its answer was lost past the retries, or the
     *     client shut down before a retry (`client_closed`). getResults() holds every recipient.
     * @throws EmailError The client sent nothing: an argument check failed, with the name `validation_error` and
     *     an `invalid_*` code, such as two recipients with the same `idempotency_key`, or the client can't send,
     *     such as when it is disabled.
     */
    public function batch(array $batch): SendBatchResult
    {
        [$shared, $maxRetries] = $this->prepareBatch($batch);
        $run = EmailBatch::prepare($shared, $batch['recipients'] ?? null);
        [$appHost, $secretKey] = $this->connect(self::SEND_NOT_DONE, self::SEND_KEY_NEEDED);
        try {
            $this->sendBatch($run, $appHost, $secretKey, $maxRetries);
        } catch (EmailBatchError $failure) {
            self::logWarnings($failure->getWarnings());
            throw $failure;
        }
        $result = $run->result();
        self::logWarnings($result->getWarnings());

        return $result;
    }

    /**
     * @return array{string, string, int, array<string, Upload>, DateTimeImmutable|null} The body, the
     *     idempotency key, the retries, the files to upload by SHA-256, and the time the email waits for.
     */
    private function prepareSend(array $email): array
    {
        $email = array_filter($email, static fn($value): bool => $value !== null);
        [$to, $distinctId] = EmailRecipient::address($email);
        $hasTemplateId = array_key_exists('template_id', $email);
        if ($hasTemplateId === array_key_exists('template_key', $email)) {
            throw new EmailError(
                'invalid_form',
                'Send either template_id, or template_key with subject and html or text, so no email was sent.'
            );
        }
        [$payload, $files] = $hasTemplateId ? [self::templateContent($email), []] : self::codeContent($email);
        $idempotencyKey = EmailRecipient::idempotencyKey($email);
        $maxRetries = $this->maxRetries($email);
        $scheduledAt = array_key_exists('scheduled_at', $email) ? self::scheduledAt($email['scheduled_at']) : null;
        $payload['to'] = $to;
        if ($distinctId !== null) {
            $payload['distinct_id'] = $distinctId;
        }
        if ($scheduledAt !== null) {
            $payload['scheduled_at'] = $scheduledAt->format('Y-m-d\TH:i:s.v\Z');
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

        return [$body, $idempotencyKey ?? Uuid::v4(), $maxRetries, $files, $scheduledAt];
    }

    /**
     * @return array{array<string, mixed>, int} The email every recipient gets, and the retries of each request.
     */
    private function prepareBatch(array $batch): array
    {
        $batch = array_filter($batch, static fn($value): bool => $value !== null);
        foreach (self::FIELDS_A_BATCH_REFUSES as $field => $fix) {
            if (array_key_exists($field, $batch)) {
                throw new EmailError(
                    'invalid_form',
                    "A batch doesn't take $field, so no email was sent.",
                    fix: $fix,
                    field: $field
                );
            }
        }
        $isTemplateForm = array_key_exists('template_id', $batch);
        if ($isTemplateForm === array_key_exists('template_key', $batch)) {
            throw new EmailError(
                'invalid_form',
                'Send a batch with either template_id, or template_key with subject and html or text, so no email '
                . 'was sent.'
            );
        }
        $maxRetries = $this->maxRetries($batch);
        unset($batch['recipients']);
        $shared = $isTemplateForm ? self::templateContent($batch, 'batch') : self::codeContent($batch, 'batch')[0];

        return [$shared, $maxRetries];
    }

    private function maxRetries(array $call): int
    {
        $maxRetries = $call['max_retries'] ?? $this->maxRetries;
        if (!is_int($maxRetries) || $maxRetries < 0) {
            throw self::invalid('invalid_max_retries', 'max_retries must be a nonnegative integer.', 'max_retries');
        }

        return $maxRetries;
    }

    private static function scheduledAt(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return self::roundedUpToTheMillisecond(DateTimeImmutable::createFromInterface($value));
        }
        [$instant, $finerThanAMillisecond] = self::timeWithOffset($value) ?? [null, false];
        if ($instant === null) {
            throw new EmailError(
                'invalid_scheduled_at',
                'scheduled_at must be a DateTimeInterface, or an ISO 8601 time with an offset, so no email was sent.',
                fix: 'Pass a time such as 2026-12-01T09:00:00Z or 2026-12-01T10:00:00+01:00, or a DateTimeImmutable.',
                field: 'scheduled_at'
            );
        }

        return $finerThanAMillisecond ? $instant->modify('+1 millisecond') : $instant;
    }

    /**
     * @return array{DateTimeImmutable, bool}|null The time in UTC cut to the millisecond, and whether the
     *     value was finer than that.
     */
    private static function timeWithOffset(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match(self::SCHEDULED_AT_PATTERN, $value, $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }
        [, $date, $hour, $minute, $second, $fraction, $offsetHours, $offsetMinutes] = $parts;
        $second ??= '00';
        $fraction ??= '';
        $offset = $offsetHours === null ? '+00:00' : "$offsetHours:$offsetMinutes";
        if (abs((int) $offsetHours) > 23 || (int) $offsetMinutes > 59) {
            return null;
        }
        $milliseconds = str_pad(substr($fraction, 0, 3), 3, '0');
        $instant = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.vP',
            "{$date}T$hour:$minute:$second.$milliseconds$offset"
        );
        if ($instant === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        return [$instant->setTimezone(new DateTimeZone('UTC')), trim(substr($fraction, 3), '0') !== ''];
    }

    private static function roundedUpToTheMillisecond(DateTimeImmutable $time): DateTimeImmutable
    {
        $time = $time->setTimezone(new DateTimeZone('UTC'));
        $belowAMillisecond = (int) $time->format('u') % 1000;

        return $belowAMillisecond === 0 ? $time : $time->modify('+' . (1000 - $belowAMillisecond) . ' usec');
    }

    /**
     * @return array<string, mixed>
     */
    private static function templateContent(array $email, string $call = 'send'): array
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
        self::refuseFieldsOutside($email, true, $call);

        return [
            'template_id' => strtolower($templateId),
            'message_data' => $templateData === [] ? (object) [] : $templateData,
        ];
    }

    /**
     * @return array{array<string, mixed>, array<string, Upload>}
     */
    private static function codeContent(array $email, string $call = 'send'): array
    {
        $templateKey = $email['template_key'];
        if (!is_string($templateKey) || preg_match(self::TEMPLATE_KEY_PATTERN, $templateKey) !== 1) {
            throw self::invalid(
                'invalid_template_key',
                'template_key can only have letters, digits, hyphens (-) and underscores (_), at most 400.',
                'template_key'
            );
        }
        self::refuseFieldsOutside($email, false, $call);
        $subject = $email['subject'] ?? null;
        if (!is_string($subject) || $subject === '') {
            throw self::invalid('invalid_form', "A $call with template_key needs a nonempty subject.", 'subject');
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
                "A $call with template_key needs a nonempty html, text or both.",
                array_key_exists('html', $content) || !array_key_exists('text', $content) ? 'html' : 'text'
            );
        }
        if (array_key_exists('from', $email)) {
            if (!is_string($email['from']) || trim($email['from']) === '') {
                throw self::invalid('invalid_form', 'from must be a nonempty string.', 'from');
            }
            $content['from'] = $email['from'];
        }
        foreach (['reply_to', 'cc', 'bcc'] as $field) {
            if (array_key_exists($field, $email) && $email[$field] !== []) {
                $content[$field] = self::addresses($field, $email[$field]);
            }
        }
        $files = [];
        if (array_key_exists('attachments', $email) && $email['attachments'] !== []) {
            $html = $content['html'] ?? '';
            $text = ($content['text'] ?? '') === '' ? $html : $content['text'];
            [$content['attachments'], $files] = self::attachments(
                $email['attachments'],
                $content['subject'],
                [$html, $text]
            );
        }
        $tracking = self::tracking($email);
        $captureEngagement = $email['capture_engagement'] ?? null;
        if ($captureEngagement !== null && !is_bool($captureEngagement)) {
            throw self::invalid('invalid_form', 'capture_engagement must be true or false.', 'capture_engagement');
        }
        if ($captureEngagement !== null) {
            $content['capture_engagement'] = $captureEngagement;
        }
        $sensitive = $email['sensitive'] ?? null;
        if ($sensitive !== null && !is_bool($sensitive)) {
            throw self::invalid('invalid_form', 'sensitive must be true or false.', 'sensitive');
        }
        if ($sensitive !== null) {
            $content['sensitive'] = $sensitive;
        }
        if ($tracking !== null) {
            $content['tracking'] = $tracking;
        }

        return [$content, $files];
    }

    private static function tracking(array $email): bool|object|null
    {
        $tracking = $email['tracking'] ?? null;
        if ($tracking === null || is_bool($tracking)) {
            return $tracking;
        }
        if (!is_array($tracking) || ($tracking !== [] && array_is_list($tracking))) {
            throw self::invalid(
                'invalid_form',
                "tracking must be true, false or an array such as ['clicks' => true].",
                'tracking'
            );
        }
        $tracking = array_filter($tracking, static fn($value): bool => $value !== null);
        foreach ($tracking as $kind => $on) {
            if (!is_bool($on)) {
                throw self::invalid('invalid_form', "tracking['$kind'] must be true or false.", 'tracking');
            }
        }

        return (object) $tracking;
    }

    /**
     * @return list<string>
     */
    private static function addresses(string $field, mixed $value): array
    {
        $addresses = is_string($value) ? [$value] : $value;
        if (!is_array($addresses) || !array_is_list($addresses)) {
            throw self::invalid('invalid_form', "$field must be one address or a list of addresses.", $field);
        }
        foreach ($addresses as $address) {
            if (!is_string($address) || trim($address) === '') {
                throw self::invalid('invalid_form', "$field must not hold a blank address.", $field);
            }
        }

        return $addresses;
    }

    /**
     * @param list<string> $bodies
     * @return array{list<array<string, string>>, array<string, Upload>}
     */
    private static function attachments(mixed $files, string $subject, array $bodies): array
    {
        if (!is_array($files) || !array_is_list($files)) {
            throw self::invalidAttachment('attachments must be a list of files.', 'attachments');
        }
        if (count($files) > self::MAX_ATTACHMENTS) {
            throw new EmailError(
                'too_many_attachments',
                'An email carries at most ' . self::MAX_ATTACHMENTS . ' files, so no email was sent.',
                why: 'An email carries at most ' . self::MAX_ATTACHMENTS . ' files.',
                fix: 'Attach at most ' . self::MAX_ATTACHMENTS . ' files, and link to any others from the email body.',
                field: 'attachments'
            );
        }
        $attachments = [];
        $messageBytes = self::MESSAGE_HEADERS_BYTES + self::encodedSubjectBytes($subject);
        foreach ($files as $index => $file) {
            $attachments[] = $attachment = self::attachment("attachments__$index", $file);
            $messageBytes += self::base64TransferBytes(strlen($attachment['content']));
        }
        foreach ($bodies as $body) {
            $messageBytes += self::base64TransferBytes(strlen($body));
        }
        if ($messageBytes > self::MAX_MESSAGE_BYTES) {
            throw new EmailError(
                'message_too_large',
                'These files make the email larger than 40 MB after Base64 encoding, so no email was sent.',
                why: 'An email can be at most ' . self::MESSAGE_LIMIT . '.',
                fix: 'Send fewer or smaller files, or link to large files from the email body.',
                field: 'attachments'
            );
        }
        $references = [];
        $uploads = [];
        foreach ($attachments as $index => $attachment) {
            $sha256 = hash('sha256', $attachment['content']);
            $uploads[$sha256] ??= [
                'filename' => $attachment['filename'],
                'size' => strlen($attachment['content']),
                'content' => $attachment['content'],
                'field' => "attachments__{$index}__content",
            ];
            unset($attachment['content']);
            $references[] = ['sha256' => $sha256, ...$attachment];
        }

        return [$references, $uploads];
    }

    private static function base64TransferBytes(int $bytes): float
    {
        return ceil(ceil($bytes / 3) * 4 * 78 / 76);
    }

    private static function encodedSubjectBytes(string $subject): float
    {
        return ceil(strlen($subject) / 42) * 75;
    }

    /**
     * @return array<string, string>
     */
    private static function attachment(string $field, mixed $file): array
    {
        if (is_array($file)) {
            $file = array_filter($file, static fn($value): bool => $value !== null);
        }
        if (!is_array($file) || ($file !== [] && array_is_list($file))) {
            throw self::invalidAttachment(
                "An attachment is an array with a filename and the file's bytes as its content.",
                $field
            );
        }
        if (array_key_exists('path', $file)) {
            throw self::invalidAttachment(
                "PostHog can't fetch an attachment from a path or URL. Read the file and pass its bytes as content.",
                "{$field}__path"
            );
        }
        foreach (array_keys($file) as $name) {
            if (!in_array($name, self::ATTACHMENT_FIELDS, true)) {
                throw self::invalidAttachment(
                    "An attachment takes filename, content, content_type and content_id, not $name.",
                    "{$field}__$name"
                );
            }
        }
        if (!self::isUtf8Text($file['filename'] ?? null)) {
            throw self::invalidAttachment('An attachment needs its filename as a UTF-8 string.', "{$field}__filename");
        }
        if (!is_string($file['content'] ?? null) || $file['content'] === '') {
            throw self::invalidAttachment(
                "An attachment needs the file's bytes as a nonempty string, such as file_get_contents() returns.",
                "{$field}__content"
            );
        }
        $attachment = ['filename' => $file['filename'], 'content' => $file['content']];
        foreach (['content_type', 'content_id'] as $name) {
            if (!array_key_exists($name, $file)) {
                continue;
            }
            if (!self::isUtf8Text($file[$name])) {
                throw self::invalidAttachment("An attachment's $name must be a UTF-8 string.", "{$field}__$name");
            }
            $attachment[$name] = $file[$name];
        }

        return $attachment;
    }

    private static function isUtf8Text(mixed $value): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8');
    }

    private static function invalidAttachment(string $message, string $field): EmailError
    {
        return new EmailError('invalid_attachment', $message . ' No email was sent.', field: $field);
    }

    private static function refuseFieldsOutside(array $email, bool $isTemplateForm, string $call): void
    {
        $formFields = array_merge(self::SHARED_FIELDS, $isTemplateForm ? self::TEMPLATE_FIELDS : self::CODE_FIELDS);
        $otherFields = $isTemplateForm ? self::CODE_FIELDS : self::TEMPLATE_FIELDS;
        foreach (array_keys($email) as $field) {
            if (in_array($field, $formFields, true)) {
                continue;
            }
            $field = (string) $field;
            $formName = $isTemplateForm ? 'template_id' : 'template_key';
            $message = "A $call with $formName doesn't take $field, so no email was sent.";
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
     * @param array<string, Upload> $files
     */
    private function upload(string $appHost, string $secretKey, array $files, int $maxRetries): void
    {
        $request = [];
        foreach ($files as $sha256 => $file) {
            $request[] = ['sha256' => (string) $sha256, 'size' => $file['size']];
        }
        $body = json_encode(['files' => $request], JSON_THROW_ON_ERROR);
        $headers = $this->headers($secretKey, ['Content-Type: application/json']);
        $url = $appHost . self::UPLOADS_PATH;
        $plainHttp = str_starts_with(strtolower($appHost), 'http://');
        $slots = $this->withRetries($maxRetries, function () use ($url, $headers, $body, $files, $plainHttp): array {
            return self::parseUploadReply($files, $plainHttp, ...$this->request('POST', $url, $headers, $body));
        });
        foreach ($slots as $sha256 => $slot) {
            $this->refuseWhenClosed(self::SEND_NOT_DONE);
            $file = $files[$sha256];
            $this->withRetries($maxRetries, function () use ($slot, $file): void {
                $this->put($slot['url'], $slot['headers'], $file);
            });
        }
    }

    /**
     * @param list<string> $headers
     * @param Upload $file
     */
    private function put(string $url, array $headers, array $file): void
    {
        $timeoutMs = $this->requestTimeoutMs + intdiv($file['size'], self::UPLOAD_BYTES_PER_EXTRA_MS);
        try {
            [$status, $body, $answerHeaders] = ($this->transport)(
                'PUT',
                $url,
                $headers,
                $file['content'],
                $timeoutMs,
                $this->requestTimeoutMs
            );
        } catch (EmailError $error) {
            if ($error->getErrorCode() !== 'network_error') {
                throw $error;
            }
            throw new EmailError(
                'network_error',
                "A file didn't reach PostHog's file storage, so no email was sent: " . $error->getMessage(),
                field: $file['field'],
                previous: $error
            );
        }
        if ($status >= 200 && $status < 300) {
            return;
        }
        if ($status >= 300 && $status < 400) {
            throw new EmailError(
                'attachment_upload_failed',
                self::onOneLogLine(
                    "PostHog's file storage redirected the upload of {$file['filename']} (HTTP $status), and the "
                    . "client doesn't follow an upload's redirect, so no email was sent."
                ),
                $status,
                why: 'A signed upload never succeeds through a redirect, and following one would send the file to '
                    . 'another host, maybe unencrypted.',
                fix: "Fix the file storage's URL.",
                field: $file['field']
            );
        }
        $answer = "HTTP $status";
        if (preg_match('#<Code>([^<]{1,100})</Code>#', $body, $code) === 1) {
            $answer .= ' ' . html_entity_decode($code[1], ENT_XML1);
            if (preg_match('#<Message>([^<]{1,300})</Message>#', $body, $message) === 1) {
                $answer .= ': ' . html_entity_decode($message[1], ENT_XML1);
            }
        }
        throw new EmailError(
            'attachment_upload_failed',
            self::onOneLogLine(
                "PostHog's file storage refused the upload of {$file['filename']}, so no email was sent. "
                . 'It answered ' . rtrim($answer, '.') . '.'
            ),
            $status,
            why: 'PostHog sends an email with files only after every file reached its file storage.',
            fix: 'Send the email again. The client asks for a new upload URL on every send.',
            field: $file['field'],
            retryAfterMs: EmailReplies::retryAfterMs($answerHeaders)
        );
    }

    /**
     * @param array<string, Upload> $files
     * @param list<string> $headers
     * @return array<string, array{url: string, headers: list<string>}>
     */
    private static function parseUploadReply(
        array $files,
        bool $plainHttp,
        int $status,
        string $body,
        array $headers
    ): array {
        $fields = EmailReplies::fields($body);
        if ($status === 404) {
            $fields['code'] = EmailReplies::text($fields['code'] ?? null) ?? 'not_found';
            $fields['attr'] = 'attachments';
            throw EmailReplies::error($fields, $status, $headers);
        }
        if ($status < 200 || $status >= 300) {
            $fields['attr'] = self::attachmentField(EmailReplies::text($fields['attr'] ?? null), $files);
            throw EmailReplies::error($fields, $status, $headers);
        }
        $slots = [];
        foreach (is_array($fields['files'] ?? null) ? $fields['files'] : [] as $slot) {
            if (is_array($slot) && is_string($slot['sha256'] ?? null)) {
                $slots[$slot['sha256']] = $slot;
            }
        }
        $uploads = [];
        foreach (array_keys($files) as $sha256) {
            $slot = $slots[$sha256] ?? null;
            if (($slot['status'] ?? null) === 'present') {
                continue;
            }
            $upload = ($slot['status'] ?? null) === 'upload' ? self::uploadTarget($slot) : null;
            if ($upload === null) {
                throw new EmailError(
                    'api_error',
                    "PostHog didn't answer where to upload a file, so no email was sent.",
                    $status
                );
            }
            if (!$plainHttp && !str_starts_with(strtolower($upload['url']), 'https://')) {
                throw new EmailError(
                    'api_error',
                    "PostHog named an upload URL that isn't https, so {$files[$sha256]['filename']} would travel "
                        . 'unencrypted, and no email was sent.',
                    $status,
                    fix: "Fix the file storage's URL. For local development over plain HTTP, set an http:// app_host.",
                    field: $files[$sha256]['field']
                );
            }
            $uploads[$sha256] = $upload;
        }

        return $uploads;
    }

    /**
     * @return array{url: string, headers: list<string>}|null
     */
    private static function uploadTarget(array $slot): ?array
    {
        $url = $slot['url'] ?? null;
        $scheme = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : '';
        $headers = $slot['headers'] ?? null;
        if (
            !in_array($scheme, ['http', 'https'], true)
            || !is_string($slot['method'] ?? null)
            || strtoupper($slot['method']) !== 'PUT'
            || !is_array($headers)
            || ($headers !== [] && array_is_list($headers))
        ) {
            return null;
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            if (
                preg_match(self::HEADER_NAME_PATTERN, (string) $name) !== 1
                || !is_string($value)
                || strpbrk($value, "\r\n\0") !== false
            ) {
                return null;
            }
            $lines[] = "$name: $value";
        }

        return ['url' => $url, 'headers' => $lines];
    }

    /**
     * @param array<string, Upload> $files
     */
    private static function attachmentField(?string $uploadField, array $files): ?string
    {
        if ($uploadField === null || preg_match('/^files(?:__(\d+))?(?:__|$)/D', $uploadField, $match) !== 1) {
            return $uploadField;
        }
        $index = $match[1] ?? null;

        return $index === null ? 'attachments' : array_values($files)[(int) $index]['field'] ?? 'attachments';
    }

    private function sendBatch(EmailBatch $run, string $appHost, string $secretKey, int $maxRetries): void
    {
        $headers = $this->headers($secretKey, ['Content-Type: application/json']);
        $timeoutMs = max($this->requestTimeoutMs, self::BATCH_REQUEST_TIMEOUT_MS);
        $closedBeforeARetry = new EmailError(
            'client_closed',
            "The PostHog client is shut down, so it didn't retry the batch."
        );
        $url = $appHost . self::BATCH_PATH;
        $body = $run->requestBody();
        try {
            $this->withRetries($maxRetries, function () use ($run, $url, $headers, $body, $timeoutMs): void {
                try {
                    $reply = $this->request('POST', $url, $headers, $body, $timeoutMs);
                } catch (EmailError $error) {
                    throw in_array($error->getErrorCode(), self::LOST_ANSWER_CODES, true) ? $run->lost($error) : $error;
                }
                $run->answer(...$reply);
            }, $closedBeforeARetry);
        } catch (EmailError $error) {
            throw $run->failure($error);
        } catch (Throwable $throwable) {
            throw $run->interrupted(new EmailError(
                'client_error',
                'The client failed while sending the batch: ' . $throwable->getMessage(),
                previous: $throwable
            ));
        }
    }

    /**
     * @return array{string, string}
     */
    private function connect(string $notDone, string $keyNeeded): array
    {
        if (($this->isDisabled)()) {
            throw new EmailError(
                'client_disabled',
                "The PostHog client is disabled because it has no project API key, so $notDone."
            );
        }
        $this->refuseWhenClosed($notDone);
        if ($this->appHost === null) {
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

    private static function refuseSchedulingOutsideCloud(string $appHost): void
    {
        if (in_array($appHost, self::CLOUD_APP_HOSTS, true)) {
            return;
        }
        $where = self::hostAndPort($appHost) ?? 'a host that is not PostHog Cloud';
        throw new EmailError(
            'scheduling_requires_cloud',
            self::onOneLogLine(
                'Scheduled sends work only on PostHog Cloud (us.posthog.com or eu.posthog.com), and this client '
                . "sends email to $where, so no email was sent."
            ),
            why: "A PostHog that doesn't schedule emails would send this one at once.",
            fix: 'Send without scheduled_at, or set app_host to PostHog Cloud. A reverse proxy or custom domain '
                . "can't schedule emails yet.",
            field: 'scheduled_at'
        );
    }

    private static function hostAndPort(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $port = parse_url($url, PHP_URL_PORT);

        return is_int($port) ? "$host:$port" : $host;
    }

    private function refuseWhenClosed(string $notDone): void
    {
        if (($this->isClosed)()) {
            throw new EmailError('client_closed', "The PostHog client is shut down, so $notDone.");
        }
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
     * @param EmailError|null $closedBeforeARetry What to throw when the client shuts down before a retry,
     *     instead of the last attempt's error.
     * @return T
     */
    private function withRetries(int $maxRetries, Closure $attemptOnce, ?EmailError $closedBeforeARetry = null): mixed
    {
        for ($attempt = 0;; $attempt++) {
            try {
                return $attemptOnce();
            } catch (EmailError $error) {
                $delayMs = self::retryDelayMs($error, $attempt, $maxRetries);
                if ($delayMs === null) {
                    throw $error;
                }
                $this->waitToRetry($delayMs, $closedBeforeARetry ?? $error);
            }
        }
    }

    private function waitToRetry(int $delayMs, EmailError $error): void
    {
        $clock = self::forwardOnlyClock();
        $started = $clock === null ? hrtime() : null;
        $start = $clock?->now();
        while (!($this->isClosed)()) {
            $elapsedUs = $clock === null
                ? self::microsecondsSince($started)
                : self::microsecondsBetween($start, $clock->now());
            $remainingUs = $delayMs * 1000 - $elapsedUs;
            if ($remainingUs <= 0) {
                return;
            }
            $clock === null ? usleep($remainingUs) : $clock->sleep($remainingUs / 1_000_000);
        }

        throw $error;
    }

    private static function forwardOnlyClock(): ?ClockInterface
    {
        try {
            $clock = Clock::get();
            if (!$clock instanceof NativeClock) {
                return $clock;
            }
        } catch (Throwable) {
        }
        try {
            return new MonotonicClock();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array{int, int} $started
     */
    private static function microsecondsSince(array $started): int
    {
        [$seconds, $nanoseconds] = hrtime();

        return ($seconds - $started[0]) * 1_000_000 + intdiv($nanoseconds - $started[1], 1000);
    }

    private static function microsecondsBetween(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $seconds = (int) (self::wholeEpochSecondsAsFloat($end) - self::wholeEpochSecondsAsFloat($start));

        return $seconds * 1_000_000 + (int) $end->format('u') - (int) $start->format('u');
    }

    private static function wholeEpochSecondsAsFloat(DateTimeImmutable $time): float
    {
        return (float) $time->format('U');
    }

    private static function onOneLogLine(string $text): string
    {
        return addcslashes($text, "\0..\37\177\\");
    }

    private static function isRetryable(EmailError $error): bool
    {
        $status = $error->getStatusCode();
        if ($status === null) {
            return $error->getErrorCode() === 'network_error';
        }
        if ($error->getErrorCode() === 'attachment_upload_failed') {
            return in_array($status, [429, 500, 502, 503, 504], true);
        }
        if ($status === 409) {
            return $error->getErrorCode() === 'idempotency_key_in_flight';
        }
        if ($status === 429) {
            return !in_array($error->getErrorCode(), ['quota_limited', 'upload_quota_exceeded'], true);
        }

        return $status === 503;
    }

    private static function retryDelayMs(EmailError $error, int $attempt, int $maxRetries): ?int
    {
        if ($attempt >= $maxRetries || !self::isRetryable($error)) {
            return null;
        }
        $retryAfterMs = $error->getRetryAfterMs();
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
    private function request(string $method, string $url, array $headers, ?string $body, ?int $timeoutMs = null): array
    {
        $timeoutMs ??= $this->requestTimeoutMs;

        return ($this->transport)($method, $url, $headers, $body, $timeoutMs, $this->requestTimeoutMs);
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
        int $timeoutMs,
        int $connectTimeoutMs
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
            CURLOPT_HTTPHEADER => [...$headers, self::NO_EXPECT_100_CONTINUE],
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
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
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
    private static function parseSendReply(
        ?DateTimeImmutable $scheduledAt,
        bool $mayGoNow,
        int $status,
        string $body,
        array $headers
    ): SendEmailResult {
        $fields = EmailReplies::fields($body);
        if ($status < 200 || $status >= 300) {
            throw EmailReplies::error($fields, $status, $headers);
        }
        $id = EmailReplies::text($fields['message_id'] ?? null);
        if ($id === null) {
            throw new EmailError('api_error', 'PostHog answered without a message ID.', $status);
        }
        $warnings = EmailReplies::warnings($fields);
        $replayed = ($fields['replayed'] ?? false) === true;
        if ($scheduledAt === null || ($mayGoNow && self::sendsNow($fields))) {
            return new SendEmailResult($id, $replayed, $warnings);
        }
        if (!self::confirmsSchedule($fields, $scheduledAt)) {
            self::logWarnings($warnings);
            throw self::scheduleNotConfirmed($id, $status);
        }

        return new ScheduledEmailResult($id, $replayed, $warnings, $scheduledAt);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function sendsNow(array $fields): bool
    {
        return ($fields['status'] ?? null) === 'queued'
            && array_key_exists('scheduled_at', $fields)
            && $fields['scheduled_at'] === null;
    }

    private static function isAtMostAMinuteAhead(DateTimeImmutable $requested): bool
    {
        return $requested <= self::now()->modify('+' . self::SEND_NOW_LEEWAY_SECONDS . ' seconds');
    }

    private static function now(): DateTimeImmutable
    {
        // symfony/clock 6.2 has no Clock class, and up to 6.3.0 its NativeClock raises a deprecation on PHP 8.4+
        // that an app may turn into an exception.
        try {
            return DateTimeImmutable::createFromInterface(Clock::get()->now())->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function confirmsSchedule(array $fields, DateTimeImmutable $requested): bool
    {
        [$confirmed] = self::timeWithOffset($fields['scheduled_at'] ?? null) ?? [null];

        return ($fields['status'] ?? null) === 'scheduled' && $confirmed !== null && $confirmed == $requested;
    }

    private static function scheduleNotConfirmed(string $messageId, int $status): EmailError
    {
        return new EmailError(
            'schedule_not_confirmed',
            self::onOneLogLine(
                "PostHog accepted email $messageId without confirming its scheduled_at, so it may go out at "
                . 'another time.'
            ),
            $status,
            fix: self::onOneLogLine(
                "Read the email's status with emails()->get('$messageId') before you send it again: a send with "
                . 'another idempotency_key, or without one, sends a second email.'
            ),
            field: 'scheduled_at',
            messageId: $messageId
        );
    }

    /**
     * @param list<string> $headers
     */
    private static function parseStatusReply(int $status, string $body, array $headers): EmailStatus
    {
        $fields = EmailReplies::fields($body);
        if ($status < 200 || $status >= 300) {
            throw EmailReplies::error($fields, $status, $headers);
        }
        $id = EmailReplies::text($fields['id'] ?? null);
        $deliveryStatus = EmailReplies::text($fields['status'] ?? null);
        $createdAt = self::timestamp($fields['created_at'] ?? null);
        $statusAt = self::timestamp($fields['status_at'] ?? null);
        if ($id === null || $deliveryStatus === null || $createdAt === null || $statusAt === null) {
            throw new EmailError('api_error', 'PostHog answered without an email status.', $status);
        }

        $reason = EmailReplies::text($fields['reason'] ?? null);

        return new EmailStatus($id, $deliveryStatus, $createdAt, $statusAt, $reason);
    }

    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        $text = EmailReplies::text($value);
        if ($text === null || preg_match(self::TIMESTAMP_PATTERN, $text) !== 1) {
            return null;
        }
        try {
            return new DateTimeImmutable($text);
        } catch (\Exception) {
            return null;
        }
    }

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
