<?php

namespace PostHog\Test;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\EmailError;
use PostHog\Emails;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

class EmailsTest extends TestCase
{
    private const TEMPLATE_ID = '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b';
    private const MESSAGE_ID = '01a18356-c56c-0001-e494-7666bc915188';
    private const SEND_URL = 'https://us.posthog.com/api/projects/@current/messaging/send/email/';
    private const INVITE = "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n";
    private const INVITE_BASE64 = 'QkVHSU46VkNBTEVOREFSDQpFTkQ6VkNBTEVOREFSDQo=';
    private const SECRET_CONTENT = 'secret-file-content';

    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string, timeoutMs: int, at: DateTimeImmutable}> */
    private array $requests = [];
    /** @var list<array{int, string, list<string>}|EmailError> */
    private array $replies = [];
    private ClockInterface $previousClock;
    private MockClock $clock;

    protected function setUp(): void
    {
        require_once __DIR__ . '/error_log_mock.php';
        global $errorMessages;
        $errorMessages = [];
        $this->previousClock = Clock::get();
        $this->clock = new MockClock('2026-11-02 12:00:00');
        Clock::set($this->clock);
    }

    protected function tearDown(): void
    {
        Clock::set($this->previousClock);
    }

    private function emails(array $config = []): Emails
    {
        return new Emails(
            $config['secretKey'] ?? 'phs_secret',
            $config['host'] ?? 'us.i.posthog.com',
            appHost: $config['appHost'] ?? null,
            maxRetries: $config['maxRetries'] ?? Emails::DEFAULT_MAX_RETRIES,
            migratedFrom: $config['migratedFrom'] ?? null,
            userAgent: 'posthog-php/9.9.9',
            isDisabled: $config['isDisabled'] ?? null,
            isClosed: $config['isClosed'] ?? null,
            transport: Closure::fromCallable([$this, 'transport'])
        );
    }

    /**
     * @param list<string> $headers
     * @return array{int, string, list<string>}
     */
    public function transport(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        $at = Clock::get()->now();
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeoutMs', 'at');
        $reply = array_shift($this->replies) ?? [202, '{"message_id":"msg_1"}', []];
        if ($reply instanceof EmailError) {
            throw $reply;
        }

        return $reply;
    }

    /** @param array{int, string, list<string>}|EmailError ...$replies */
    private function reply(...$replies): void
    {
        $this->replies = $replies;
    }

    private function body(int $index = 0): array
    {
        return json_decode($this->requests[$index]['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function header(string $name, int $index = 0): ?string
    {
        foreach ($this->requests[$index]['headers'] as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    /**
     * Epoch milliseconds overflow an int on 32-bit PHP, so a wait is measured as a difference of floats.
     */
    private static function msBetween(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int) round(((float) $end->format('U.u') - (float) $start->format('U.u')) * 1000);
    }

    private function sendError(array $email, ?Emails $emails = null): EmailError
    {
        try {
            ($emails ?? $this->emails())->send($email);
        } catch (EmailError $error) {
            return $error;
        }
        $this->fail('send() did not throw EmailError');
    }

    private function getError(string $messageId, ?Emails $emails = null): EmailError
    {
        try {
            ($emails ?? $this->emails())->get($messageId);
        } catch (EmailError $error) {
            return $error;
        }
        $this->fail('get() did not throw EmailError');
    }

    public function testTemplateSendPostsTheFlatBody(): void
    {
        $result = $this->emails()->send([
            'to' => 'user@example.com',
            'template_id' => strtoupper(self::TEMPLATE_ID),
            'template_data' => ['name' => 'Ada'],
            'distinct_id' => 'user_123',
            'idempotency_key' => 'welcome:user_123',
        ]);

        $this->assertSame('msg_1', $result->getId());
        $this->assertFalse($result->isReplayed());
        $this->assertSame([], $result->getWarnings());
        $this->assertCount(1, $this->requests);
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame(self::SEND_URL, $this->requests[0]['url']);
        $this->assertSame(
            '{"template_id":"' . self::TEMPLATE_ID . '","message_data":{"name":"Ada"},'
            . '"to":"user@example.com","distinct_id":"user_123"}',
            $this->requests[0]['body']
        );
        $this->assertSame('Bearer phs_secret', $this->header('Authorization'));
        $this->assertSame('application/json', $this->header('Content-Type'));
        $this->assertSame('application/json', $this->header('Accept'));
        $this->assertSame('posthog-php/9.9.9', $this->header('User-Agent'));
        $this->assertSame('welcome:user_123', $this->header('Idempotency-Key'));
        $this->assertNull($this->header('X-PostHog-Migrated-From'));
        $this->assertSame(15000, $this->requests[0]['timeoutMs']);
    }

    public function testTemplateSendWithoutDataSendsAnEmptyObject(): void
    {
        $this->emails()->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame(
            '{"template_id":"' . self::TEMPLATE_ID . '","message_data":{},"to":"user@example.com"}',
            $this->requests[0]['body']
        );
    }

    public function testCodeSendPostsRenderedContent(): void
    {
        $this->emails()->send([
            'to' => 'user@example.com',
            'template_key' => 'password-reset',
            'subject' => 'Reset your password',
            'html' => '<p>Reset it <a href="https://example.com/r">here</a></p>',
            'text' => 'Reset it here: https://example.com/r',
            'from' => 'Acme <security@example.com>',
            'reply_to' => 'help@example.com',
            'distinct_id' => 'user_123',
        ]);

        $this->assertSame([
            'template_key' => 'password-reset',
            'subject' => 'Reset your password',
            'html' => '<p>Reset it <a href="https://example.com/r">here</a></p>',
            'text' => 'Reset it here: https://example.com/r',
            'from' => 'Acme <security@example.com>',
            'reply_to' => ['help@example.com'],
            'capture_engagement' => true,
            'to' => 'user@example.com',
            'distinct_id' => 'user_123',
        ], $this->body());
    }

    public function testCodeSendKeepsAReplyToListAndCaptureEngagementOff(): void
    {
        $this->emails()->send([
            'to' => 'user@example.com',
            'template_key' => 'receipt',
            'subject' => 'Your receipt',
            'text' => 'Thanks!',
            'reply_to' => ['a@example.com', 'b@example.com'],
            'capture_engagement' => false,
        ]);

        $this->assertSame([
            'template_key' => 'receipt',
            'subject' => 'Your receipt',
            'text' => 'Thanks!',
            'reply_to' => ['a@example.com', 'b@example.com'],
            'capture_engagement' => false,
            'to' => 'user@example.com',
        ], $this->body());
    }

    public function testNullOptionsCountAsLeftOut(): void
    {
        $this->emails()->send([
            'to' => 'user@example.com',
            'template_key' => 'receipt',
            'subject' => 'Your receipt',
            'html' => '<p>Thanks!</p>',
            'text' => null,
            'from' => null,
            'reply_to' => null,
            'cc' => null,
            'bcc' => null,
            'attachments' => null,
            'capture_engagement' => null,
            'distinct_id' => null,
            'idempotency_key' => null,
            'template_id' => null,
        ]);

        $this->assertSame([
            'template_key' => 'receipt',
            'subject' => 'Your receipt',
            'html' => '<p>Thanks!</p>',
            'capture_engagement' => true,
            'to' => 'user@example.com',
        ], $this->body());
    }

    public function testCodeSendPostsCopiesAndAttachmentsEncodedOnceForEveryAttempt(): void
    {
        $this->reply([503, '{"code":"unavailable"}', []], [202, '{"message_id":"msg_1"}', []]);
        $png = "\x89PNG\r\n\x1a\n\x00";

        $this->emails()->send([
            'to' => 'user@example.com',
            'template_key' => 'invite',
            'subject' => 'You are invited',
            'html' => '<img src="cid:logo">',
            'cc' => 'manager@example.com',
            'bcc' => ['audit@example.com', 'archive@example.com'],
            'attachments' => [
                ['filename' => 'invite.ics', 'content' => self::INVITE, 'content_type' => 'text/calendar'],
                ['filename' => 'logo.png', 'content' => $png, 'content_id' => 'logo'],
            ],
        ]);

        $this->assertSame([
            'template_key' => 'invite',
            'subject' => 'You are invited',
            'html' => '<img src="cid:logo">',
            'cc' => ['manager@example.com'],
            'bcc' => ['audit@example.com', 'archive@example.com'],
            'attachments' => [
                ['filename' => 'invite.ics', 'content' => self::INVITE_BASE64, 'content_type' => 'text/calendar'],
                ['filename' => 'logo.png', 'content' => 'iVBORw0KGgoA', 'content_id' => 'logo'],
            ],
            'capture_engagement' => true,
            'to' => 'user@example.com',
        ], $this->body());
        $this->assertSame($this->requests[0]['body'], $this->requests[1]['body']);
    }

    public function testEmptyCopyAndAttachmentListsAreLeftOut(): void
    {
        $this->emails()->send([
            'to' => 'user@example.com',
            'template_key' => 'receipt',
            'subject' => 'Your receipt',
            'text' => 'Thanks!',
            'cc' => [],
            'bcc' => [],
            'attachments' => [],
        ]);

        $this->assertSame([
            'template_key' => 'receipt',
            'subject' => 'Your receipt',
            'text' => 'Thanks!',
            'capture_engagement' => true,
            'to' => 'user@example.com',
        ], $this->body());
    }

    public function testAGeneratedIdempotencyKeyIsAUuidV4ReusedOnEveryAttempt(): void
    {
        $this->reply([503, '{"code":"unavailable"}', []], [202, '{"message_id":"msg_1"}', []]);

        $this->emails()->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertCount(2, $this->requests);
        $key = $this->header('Idempotency-Key');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $key
        );
        $this->assertSame($key, $this->header('Idempotency-Key', 1));
        $this->assertSame($this->requests[0]['body'], $this->requests[1]['body']);
    }

    public function testMigratedFromIsSentOnEveryRequest(): void
    {
        $emails = $this->emails(['migratedFrom' => 'resend']);
        $this->reply([202, '{"message_id":"msg_1"}', []], [404, '{"code":"not_found"}', []]);

        $emails->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);
        $this->getError(self::MESSAGE_ID, $emails);

        $this->assertSame('resend', $this->header('X-PostHog-Migrated-From'));
        $this->assertSame('resend', $this->header('X-PostHog-Migrated-From', 1));
    }

    public function testAReplayAndWarningsComeBackAndWarningsAreLogged(): void
    {
        global $errorMessages;
        $this->reply([202, json_encode([
            'message_id' => 'msg_1',
            'replayed' => true,
            'warnings' => [
                ['code' => 'unverified_link', 'detail' => 'A link points to an unknown domain.', 'fix' => 'Check it.'],
                'not a warning',
                ['code' => "odd\ncode", 'detail' => "Two\nlines\\"],
            ],
        ]), []]);

        $result = $this->emails()->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertTrue($result->isReplayed());
        $this->assertCount(2, $result->getWarnings());
        $warning = $result->getWarnings()[0];
        $this->assertSame('unverified_link', $warning->getCode());
        $this->assertSame('A link points to an unknown domain.', $warning->getMessage());
        $this->assertSame('Check it.', $warning->getFix());
        $this->assertSame(
            [
                '[PostHog][Emails] Email warning unverified_link: A link points to an unknown domain.',
                '[PostHog][Emails] Email warning odd\\ncode: Two\\nlines\\\\',
            ],
            $errorMessages
        );
    }

    public static function argumentErrors(): array
    {
        $template = ['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID];
        $code = ['to' => 'user@example.com', 'template_key' => 'welcome', 'subject' => 'Hi', 'html' => '<p>Hi</p>'];

        return [
            'no form' => [['to' => 'user@example.com'], 'invalid_form', null],
            'both forms' => [$template + ['template_key' => 'welcome'], 'invalid_form', null],
            'no recipient' => [['template_id' => self::TEMPLATE_ID], 'invalid_recipient', 'to'],
            'blank recipient' => [['to' => ' '] + $template, 'invalid_recipient', 'to'],
            'list of recipients' => [['to' => ['a@example.com']] + $template, 'invalid_recipient', 'to'],
            'empty distinct ID' => [$template + ['distinct_id' => ''], 'invalid_distinct_id', 'distinct_id'],
            'integer distinct ID' => [$template + ['distinct_id' => 42], 'invalid_distinct_id', 'distinct_id'],
            'template ID not a UUID' => [
                ['template_id' => 'welcome'] + $template,
                'invalid_template_id',
                'template_id',
            ],
            'UUID with a trailing newline' => [
                ['template_id' => self::TEMPLATE_ID . "\n"] + $template,
                'invalid_template_id',
                'template_id',
            ],
            'template data as a list' => [
                $template + ['template_data' => ['Ada']],
                'invalid_template_data',
                'template_data',
            ],
            'template data as a string' => [
                $template + ['template_data' => 'Ada'],
                'invalid_template_data',
                'template_data',
            ],
            'subject with a template' => [$template + ['subject' => 'Hi'], 'invalid_form', 'subject'],
            'capture_engagement with a template' => [
                $template + ['capture_engagement' => false],
                'invalid_form',
                'capture_engagement',
            ],
            'unknown option' => [$template + ['headers' => ['X-A' => 'b']], 'invalid_form', 'headers'],
            'cc with a template' => [$template + ['cc' => 'boss@example.com'], 'invalid_form', 'cc'],
            'bcc with a template' => [$template + ['bcc' => ['boss@example.com']], 'invalid_form', 'bcc'],
            'attachments with a template' => [
                $template + ['attachments' => [['filename' => 'a.txt', 'content' => 'hi']]],
                'invalid_form',
                'attachments',
            ],
            'template_data with code' => [$code + ['template_data' => ['a' => 1]], 'invalid_form', 'template_data'],
            'bad template key' => [['template_key' => 'pass word'] + $code, 'invalid_template_key', 'template_key'],
            'template key too long' => [
                ['template_key' => str_repeat('a', 401)] + $code,
                'invalid_template_key',
                'template_key',
            ],
            'no subject' => [array_diff_key($code, ['subject' => 0]), 'invalid_form', 'subject'],
            'empty subject' => [['subject' => ''] + $code, 'invalid_form', 'subject'],
            'no html or text' => [array_diff_key($code, ['html' => 0]), 'invalid_form', 'html'],
            'empty html and text' => [['html' => ''] + $code + ['text' => ''], 'invalid_form', 'html'],
            'html not a string' => [['html' => ['<p>']] + $code, 'invalid_form', 'html'],
            'empty from' => [$code + ['from' => ''], 'invalid_form', 'from'],
            'blank reply_to' => [$code + ['reply_to' => ['a@example.com', ' ']], 'invalid_form', 'reply_to'],
            'empty reply_to list' => [$code + ['reply_to' => []], 'invalid_form', 'reply_to'],
            'blank cc' => [$code + ['cc' => ' '], 'invalid_form', 'cc'],
            'bcc not text' => [$code + ['bcc' => [123]], 'invalid_form', 'bcc'],
            'cc as a map' => [$code + ['cc' => ['boss' => 'boss@example.com']], 'invalid_form', 'cc'],
            'capture_engagement not a bool' => [
                $code + ['capture_engagement' => 'no'],
                'invalid_form',
                'capture_engagement',
            ],
            'idempotency key with a space' => [
                $template + ['idempotency_key' => 'welcome user'],
                'invalid_idempotency_key',
                'idempotency_key',
            ],
            'idempotency key too long' => [
                $template + ['idempotency_key' => str_repeat('k', 256)],
                'invalid_idempotency_key',
                'idempotency_key',
            ],
            'negative max_retries' => [$template + ['max_retries' => -1], 'invalid_max_retries', 'max_retries'],
            'max_retries as a string' => [$template + ['max_retries' => '1'], 'invalid_max_retries', 'max_retries'],
            'html with invalid UTF-8' => [['html' => "\xB1\x31"] + $code, 'invalid_form', null],
        ];
    }

    #[DataProvider('argumentErrors')]
    public function testAFailedArgumentCheckSendsNothing(array $email, string $code, ?string $field): void
    {
        $error = $this->sendError($email);

        $this->assertSame($code, $error->getErrorCode());
        $this->assertSame('validation_error', $error->getErrorName());
        $this->assertSame($field, $error->getField());
        $this->assertNull($error->getStatusCode());
        $this->assertSame([], $this->requests);
    }

    public static function attachmentErrors(): array
    {
        $file = ['filename' => 'a.txt', 'content' => self::SECRET_CONTENT];

        return [
            'one file, not a list' => [$file, 'attachments'],
            'content, not a list' => [self::SECRET_CONTENT, 'attachments'],
            'file not an array' => [[self::SECRET_CONTENT], 'attachments__0'],
            'file as a list' => [[['a.txt', self::SECRET_CONTENT]], 'attachments__0'],
            'no content' => [[['filename' => 'a.txt']], 'attachments__0__content'],
            'content as a list of bytes' => [
                [['filename' => 'a.txt', 'content' => array_map('ord', str_split(self::SECRET_CONTENT))]],
                'attachments__0__content',
            ],
            'no filename' => [[['content' => self::SECRET_CONTENT]], 'attachments__0__filename'],
            'filename not text' => [[['filename' => 1] + $file], 'attachments__0__filename'],
            'path' => [[$file + ['path' => '/tmp/a.txt']], 'attachments__0__path'],
            'unknown field' => [[$file + ['contentType' => 'text/plain']], 'attachments__0__contentType'],
            'second file with content_type not text' => [
                [$file, $file + ['content_type' => 1]],
                'attachments__1__content_type',
            ],
            'content_id not text' => [[$file + ['content_id' => ['logo']]], 'attachments__0__content_id'],
        ];
    }

    #[DataProvider('attachmentErrors')]
    public function testAnAttachmentTheClientCannotSendIsRefusedWithoutRepeatingItsContent(
        mixed $attachments,
        string $field
    ): void {
        $error = $this->sendError([
            'to' => 'user@example.com',
            'template_key' => 'receipt',
            'subject' => 'Your receipt',
            'text' => 'Thanks!',
            'attachments' => $attachments,
        ]);

        $this->assertSame('invalid_attachment', $error->getErrorCode());
        $this->assertSame('validation_error', $error->getErrorName());
        $this->assertSame($field, $error->getField());
        $this->assertStringNotContainsString(self::SECRET_CONTENT, $error->getMessage() . $error->getFix());
        $this->assertSame([], $this->requests);
    }

    public function testAServerThatDoesNotTakeAFieldRefusesTheSendAndNamesIt(): void
    {
        $this->reply([400, json_encode([
            'type' => 'invalid_request',
            'code' => 'invalid_request',
            'detail' => "PostHog doesn't take this field yet, so it didn't send the email.",
            'attr' => 'attachments',
            'why' => "This PostHog version doesn't take the field yet.",
            'fix' => 'Send the email without this field.',
        ]), []]);

        $error = $this->sendError([
            'to' => 'user@example.com',
            'template_key' => 'receipt',
            'subject' => 'Your receipt',
            'text' => 'Thanks!',
            'attachments' => [['filename' => 'receipt.pdf', 'content' => '%PDF-1.7']],
        ]);

        $this->assertSame('attachments', $error->getField());
        $this->assertSame(400, $error->getStatusCode());
        $this->assertSame('Send the email without this field.', $error->getFix());
        $this->assertCount(1, $this->requests);
    }

    public function testAnOptionOfTheOtherFormSaysHowToSendIt(): void
    {
        $error = $this->sendError([
            'to' => 'user@example.com',
            'template_id' => self::TEMPLATE_ID,
            'html' => '<p>Hi</p>',
        ]);

        $this->assertSame("A send with template_id doesn't take html, so no email was sent.", $error->getMessage());
        $this->assertSame('Remove html, or send content from your code with template_key.', $error->getFix());
        $this->assertNotNull($error->getWhy());
    }

    public static function setupErrors(): array
    {
        return [
            'disabled client' => [['isDisabled' => static fn(): bool => true], 'client_disabled', 'application_error'],
            'shut down client' => [['isClosed' => static fn(): bool => true], 'client_closed', 'application_error'],
            'no secret key' => [['secretKey' => ' '], 'missing_secret_key', 'missing_api_key'],
            'custom host' => [['host' => 'https://proxy.example.com'], 'missing_app_host', 'application_error'],
        ];
    }

    #[DataProvider('setupErrors')]
    public function testASetupErrorSendsAndReadsNothing(array $config, string $code, string $name): void
    {
        $emails = $this->emails($config);

        $sendError = $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID], $emails);
        $getError = $this->getError(self::MESSAGE_ID, $emails);

        $this->assertSame($code, $sendError->getErrorCode());
        $this->assertSame($name, $sendError->getErrorName());
        $this->assertSame($code, $getError->getErrorCode());
        $this->assertSame([], $this->requests);
    }

    public function testTheSecretKeyNeverReachesAnErrorMessage(): void
    {
        $this->reply([401, '{"code":"authentication_failed","detail":"Invalid key."}', []]);

        $error = $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame('missing_api_key', $error->getErrorName());
        $this->assertStringNotContainsString('phs_secret', $error->getMessage());
    }

    public static function hosts(): array
    {
        return [
            'US ingestion host' => ['us.i.posthog.com', null, 'https://us.posthog.com'],
            'EU ingestion host with scheme' => ['https://eu.i.posthog.com/', null, 'https://eu.posthog.com'],
            'EU app host' => ['eu.posthog.com', null, 'https://eu.posthog.com'],
            'legacy app host' => ['https://app.posthog.com', null, 'https://us.posthog.com'],
            'app host wins over a custom host' => [
                'proxy.example.com',
                'https://posthog.example.com/',
                'https://posthog.example.com',
            ],
            'app host without a scheme' => ['proxy.example.com', 'posthog.example.com', 'https://posthog.example.com'],
            'http app host' => ['proxy.example.com', 'http://localhost:8010', 'http://localhost:8010'],
            'cloud ingestion host as app host' => [
                'proxy.example.com',
                'https://eu.i.posthog.com',
                'https://eu.posthog.com',
            ],
        ];
    }

    #[DataProvider('hosts')]
    public function testRequestsGoToTheAppHost(string $host, ?string $appHost, string $expected): void
    {
        $this->emails(['host' => $host, 'appHost' => $appHost])
            ->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame($expected . '/api/projects/@current/messaging/send/email/', $this->requests[0]['url']);
    }

    public static function responseErrors(): array
    {
        return [
            '400' => [400, 'invalid_recipient', 'validation_error'],
            '401' => [401, 'authentication_failed', 'missing_api_key'],
            '403' => [403, 'permission_denied', 'invalid_permission'],
            '404' => [404, 'not_found', 'not_found'],
            '409 template outdated' => [409, 'template_outdated', 'validation_error'],
            '409 in flight' => [409, 'idempotency_key_in_flight', 'concurrent_idempotent_requests'],
            '422 suppressed' => [422, 'recipient_suppressed', 'validation_error'],
            '422 key reused' => [422, 'idempotency_key_reused', 'invalid_idempotent_request'],
            '429 throttled' => [429, 'throttled', 'rate_limit_exceeded'],
            '429 quota' => [429, 'quota_limited', 'monthly_quota_exceeded'],
            '503' => [503, 'unavailable', 'service_unavailable'],
            '500' => [500, 'server_error', 'application_error'],
        ];
    }

    #[DataProvider('responseErrors')]
    public function testAnErrorKeepsPostHogsCodeAndFields(int $status, string $code, string $name): void
    {
        $body = json_encode([
            'type' => 'validation_error',
            'code' => $code,
            'detail' => 'Nope.',
            'attr' => 'to',
            'why' => 'Because.',
            'fix' => 'Do this.',
            'docs_url' => 'https://posthog.com/docs/x',
        ]);
        $this->reply(...array_fill(0, 3, [$status, $body, ['HTTP/1.1 ' . $status . ' X', 'Retry-After: 2']]));

        $error = $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID, 'max_retries' => 0]);

        $this->assertSame($code, $error->getErrorCode());
        $this->assertSame($name, $error->getErrorName());
        $this->assertSame('Nope.', $error->getMessage());
        $this->assertSame($status, $error->getStatusCode());
        $this->assertSame('to', $error->getField());
        $this->assertSame('Because.', $error->getWhy());
        $this->assertSame('Do this.', $error->getFix());
        $this->assertSame('https://posthog.com/docs/x', $error->getDocsUrl());
        $this->assertSame(2000, $error->getRetryAfterMs());
    }

    public function testAnAnswerWithoutACodeTakesTheNameOfItsStatus(): void
    {
        $this->reply(...array_fill(0, 3, [503, '<html>Service Unavailable</html>', []]));

        $error = $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame('api_error', $error->getErrorCode());
        $this->assertSame('service_unavailable', $error->getErrorName());
        $this->assertSame('PostHog answered HTTP 503.', $error->getMessage());
        $this->assertCount(3, $this->requests, 'a 503 without a code is retried');
    }

    public function testAnAcceptanceWithoutAMessageIdIsAnErrorAndNotRetried(): void
    {
        $this->reply([202, '{}', []]);

        $error = $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame('api_error', $error->getErrorCode());
        $this->assertSame(202, $error->getStatusCode());
        $this->assertCount(1, $this->requests);
    }

    public static function retriedFailures(): array
    {
        return [
            'network error' => [new EmailError('network_error', 'The request to PostHog failed.')],
            '409 in flight' => [[409, '{"code":"idempotency_key_in_flight"}', []]],
            '429 throttled' => [[429, '{"code":"throttled"}', []]],
            '503' => [[503, '{"code":"unavailable"}', []]],
        ];
    }

    /** @param array{int, string, list<string>}|EmailError $failure */
    #[DataProvider('retriedFailures')]
    public function testATransientFailureIsRetriedTwiceByDefault($failure): void
    {
        $this->reply($failure, $failure, $failure);
        $start = $this->clock->now();

        $error = $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertCount(3, $this->requests);
        $waitedMs = self::msBetween($start, $this->clock->now());
        $this->assertGreaterThanOrEqual(800 + 1600, $waitedMs);
        $this->assertLessThanOrEqual(1200 + 2400, $waitedMs);
        $this->assertSame(
            $failure instanceof EmailError ? 'network_error' : json_decode($failure[1], true)['code'],
            $error->getErrorCode()
        );
    }

    public static function failuresNotRetried(): array
    {
        return [
            '400' => [400, 'invalid_recipient'],
            '404' => [404, 'not_found'],
            '409 other' => [409, 'template_outdated'],
            '422' => [422, 'recipient_suppressed'],
            '429 quota' => [429, 'quota_limited'],
            '500' => [500, 'server_error'],
        ];
    }

    #[DataProvider('failuresNotRetried')]
    public function testAFailureThatWaitingDoesNotFixIsNotRetried(int $status, string $code): void
    {
        $this->reply([$status, json_encode(['code' => $code]), []]);

        $error = $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame($code, $error->getErrorCode());
        $this->assertCount(1, $this->requests);
    }

    public function testASendCanTurnRetriesOff(): void
    {
        $this->reply([503, '{"code":"unavailable"}', []], [202, '{"message_id":"msg_1"}', []]);

        $error = $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID, 'max_retries' => 0]);

        $this->assertSame('unavailable', $error->getErrorCode());
        $this->assertCount(1, $this->requests);
    }

    public function testTheClientRetryLimitApplies(): void
    {
        $this->reply(...array_fill(0, 5, [503, '{"code":"unavailable"}', []]));

        $emails = $this->emails(['maxRetries' => 4]);

        $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID], $emails);

        $this->assertCount(5, $this->requests);
    }

    public function testAClientShutDownDuringTheRequestDoesNotWaitToRetry(): void
    {
        // As if a signal handler called shutdown() while the first attempt was in flight.
        $emails = $this->emails(['isClosed' => fn(): bool => $this->requests !== []]);
        $this->reply([429, '{"code":"throttled"}', ['Retry-After: 30']], [202, '{"message_id":"msg_1"}', []]);
        $start = $this->clock->now();

        $error = $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID], $emails);

        $this->assertSame('throttled', $error->getErrorCode());
        $this->assertCount(1, $this->requests);
        $this->assertSame(0, self::msBetween($start, $this->clock->now()));
    }

    public function testAClientShutDownByASignalDuringTheRetryWaitSendsNothingMore(): void
    {
        $closed = false;
        $emails = $this->emails(['isClosed' => function () use (&$closed): bool {
            return $closed;
        }]);
        $shutDownBySignal = function () use (&$closed): void {
            $closed = true;
        };

        Clock::set(new InterruptedClock($this->clock, 0.5, $shutDownBySignal));
        $this->reply([503, '{"code":"unavailable"}', ['Retry-After: 2']], [202, '{"message_id":"msg_1"}', []]);
        $sendError = $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID], $emails);
        $sendRequests = count($this->requests);

        $closed = false;
        Clock::set(new InterruptedClock($this->clock, 0.5, $shutDownBySignal));
        $this->reply([503, '{"code":"unavailable"}', ['Retry-After: 2']], [200, '{}', []]);
        $this->requests = [];
        $getError = $this->getError(self::MESSAGE_ID, $emails);

        $this->assertSame('unavailable', $sendError->getErrorCode());
        $this->assertSame(1, $sendRequests);
        $this->assertSame('unavailable', $getError->getErrorCode());
        $this->assertCount(1, $this->requests);
    }

    public function testASignalDoesNotCutTheRetryWaitShort(): void
    {
        Clock::set(new InterruptedClock($this->clock, 1, static function (): void {
        }));
        $this->reply([429, '{"code":"throttled"}', ['Retry-After: 3']], [202, '{"message_id":"msg_1"}', []]);

        $result = $this->emails()->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame('msg_1', $result->getId());
        $this->assertCount(2, $this->requests);
        $this->assertSame(3000, self::msBetween($this->requests[0]['at'], $this->requests[1]['at']));
    }

    public function testALongerRetryAfterIsWaitedFor(): void
    {
        $this->reply([429, '{"code":"throttled"}', ['Retry-After: 7']], [202, '{"message_id":"msg_1"}', []]);
        $start = $this->clock->now();

        $this->emails()->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame(7000, self::msBetween($start, $this->clock->now()));
        $this->assertCount(2, $this->requests);
    }

    public static function defaultTimezones(): array
    {
        return [['UTC'], ['Asia/Tokyo'], ['America/Los_Angeles']];
    }

    #[DataProvider('defaultTimezones')]
    public function testARetryAfterAsAnHttpDateIsWaitedForInAnyDefaultTimezone(string $timezone): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set($timezone);
        try {
            $at = gmdate(DATE_RFC7231, $this->clock->now()->getTimestamp() + 10);
            $this->reply([503, '{"code":"unavailable"}', ['retry-after: ' . $at]], [202, '{"message_id":"msg_1"}', []]);
            $start = $this->clock->now();

            $this->emails()->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

            $this->assertSame(10000, self::msBetween($start, $this->clock->now()));
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function testARetryAfterOverThirtySecondsFailsAtOnce(): void
    {
        $this->reply([429, '{"code":"throttled"}', ['Retry-After: 60']]);
        $start = $this->clock->now();

        $error = $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame('throttled', $error->getErrorCode());
        $this->assertSame(60000, $error->getRetryAfterMs());
        $this->assertCount(1, $this->requests);
        $this->assertEquals($start, $this->clock->now());
    }

    public static function hugeRetryAfters(): array
    {
        return [
            'seconds' => ['9223372036854776'],
            'seconds past the float range' => [str_repeat('9', 309)],
            'an HTTP date' => ['Fri, 31 Dec 9999 23:59:59 GMT'],
        ];
    }

    #[DataProvider('hugeRetryAfters')]
    public function testAHugeRetryAfterFailsAtOnceWithoutOverflowing(string $retryAfter): void
    {
        $this->reply([429, '{"code":"throttled"}', ["Retry-After: $retryAfter"]]);

        $error = $this->sendError(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame('throttled', $error->getErrorCode());
        // A year, or what fits in an int on 32-bit PHP.
        $this->assertSame(min(31_536_000, intdiv(PHP_INT_MAX, 1000)) * 1000, $error->getRetryAfterMs());
        $this->assertCount(1, $this->requests);
    }

    public function testGetReadsTheStatus(): void
    {
        $this->reply([200, json_encode([
            'id' => self::MESSAGE_ID,
            'status' => 'bounced',
            'created_at' => '2026-10-28T16:27:44.364000Z',
            'status_at' => '2026-10-28T16:30:02.100000Z',
            'reason' => 'hard',
        ]), []]);

        $status = $this->emails()->get(strtoupper(self::MESSAGE_ID));

        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertSame(
            'https://us.posthog.com/api/projects/@current/messaging/emails/' . self::MESSAGE_ID . '/',
            $this->requests[0]['url']
        );
        $this->assertNull($this->requests[0]['body']);
        $this->assertNull($this->header('Idempotency-Key'));
        $this->assertNull($this->header('Content-Type'));
        $this->assertSame('Bearer phs_secret', $this->header('Authorization'));
        $this->assertSame(self::MESSAGE_ID, $status->getId());
        $this->assertSame('bounced', $status->getStatus());
        $this->assertSame('hard', $status->getReason());
        $this->assertSame('2026-10-28T16:27:44.364+00:00', $status->getCreatedAt()->format('Y-m-d\TH:i:s.vP'));
        $this->assertSame('2026-10-28T16:30:02.100+00:00', $status->getStatusAt()->format('Y-m-d\TH:i:s.vP'));
    }

    public function testGetPassesAnUnknownStatusThrough(): void
    {
        $this->reply([200, json_encode([
            'id' => self::MESSAGE_ID,
            'status' => 'archived',
            'created_at' => '2026-10-28T16:27:44Z',
            'status_at' => '2026-10-28T16:27:44+00:00',
        ]), []]);

        $status = $this->emails()->get(self::MESSAGE_ID);

        $this->assertSame('archived', $status->getStatus());
        $this->assertNull($status->getReason());
    }

    public static function incompleteStatuses(): array
    {
        $record = [
            'id' => self::MESSAGE_ID,
            'status' => 'sent',
            'created_at' => '2026-10-28T16:27:44Z',
            'status_at' => '2026-10-28T16:27:44Z',
        ];

        return [
            'no id' => [array_diff_key($record, ['id' => 0])],
            'no status' => [array_diff_key($record, ['status' => 0])],
            'no created_at' => [array_diff_key($record, ['created_at' => 0])],
            'status_at not a time' => [['status_at' => 'tomorrow'] + $record],
        ];
    }

    #[DataProvider('incompleteStatuses')]
    public function testGetRefusesAnIncompleteStatus(array $record): void
    {
        $this->reply([200, json_encode($record), []]);

        $error = $this->getError(self::MESSAGE_ID);

        $this->assertSame('api_error', $error->getErrorCode());
        $this->assertSame(200, $error->getStatusCode());
    }

    public function testGetDoesNotRetryAnUnknownId(): void
    {
        $this->reply([404, '{"code":"not_found","detail":"No email with this ID."}', []]);

        $error = $this->getError(self::MESSAGE_ID);

        $this->assertSame('not_found', $error->getErrorCode());
        $this->assertCount(1, $this->requests);
    }

    public function testGetRetriesAnUnavailableService(): void
    {
        $this->reply([503, '{"code":"unavailable"}', []], [200, json_encode([
            'id' => self::MESSAGE_ID,
            'status' => 'sent',
            'created_at' => '2026-10-28T16:27:44Z',
            'status_at' => '2026-10-28T16:27:44Z',
        ]), []]);

        $this->assertSame('sent', $this->emails()->get(self::MESSAGE_ID)->getStatus());
        $this->assertCount(2, $this->requests);
    }

    public function testGetRefusesAnIdThatIsNotAMessageId(): void
    {
        $error = $this->getError('msg_1');

        $this->assertSame('invalid_message_id', $error->getErrorCode());
        $this->assertSame('validation_error', $error->getErrorName());
        $this->assertSame([], $this->requests);
    }

    public function testBadClientOptionsAreRefused(): void
    {
        foreach (
            [
                ['maxRetries' => -1],
                ['migratedFrom' => "resend\r\nX-Evil: 1"],
            ] as $config
        ) {
            try {
                $this->emails($config);
                $this->fail('Expected InvalidArgumentException for ' . json_encode($config));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
