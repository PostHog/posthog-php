<?php

namespace PostHog\Test\Mailer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\EmailError;
use PostHog\Mailer\PostHogTransportException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;

/**
 * Each row of the adapter contract's errors table, as the Symfony error the app sees.
 */
final class PostHogTransportErrorsTest extends TestCase
{
    use SendsThroughStubbedHttp;

    private ClockInterface $previousClock;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../error_log_mock.php';
        $this->previousClock = Clock::get();
        Clock::set(new MockClock('2026-11-05 12:00:00'));
    }

    protected function tearDown(): void
    {
        Clock::set($this->previousClock);
    }

    private static function email(): Email
    {
        return (new Email())->from('shop@example.com')->to('ana@example.com')->subject('Hi')->text('Hi');
    }

    private static function answer(int $status, string $code, array $headers = []): array
    {
        $error = ['type' => 'x', 'code' => $code, 'detail' => "PostHog said $code.", 'attr' => 'to'];

        return [$status, json_encode($error), $headers];
    }

    public function testAnAcceptedSendSetsPostHogsMessageIdAsTheMessageId(): void
    {
        $this->reply([202, '{"message_id":"01a18356-c56c-0001-e494-7666bc915188","replayed":false}', []]);

        $sent = $this->transport()->send(self::email());

        $this->assertSame('01a18356-c56c-0001-e494-7666bc915188', $sent->getMessageId());
    }

    public static function failures(): array
    {
        $once = 1;
        $sdkRetries = 3;

        return [
            '400 invalid request' => [self::answer(400, 'invalid_input'), 400, 'invalid_input', false, null, $once],
            '401 wrong key' => [
                self::answer(401, 'authentication_failed'), 401, 'authentication_failed', false, null, $once,
            ],
            '404 template not found' => [self::answer(404, 'not_found'), 404, 'not_found', false, null, $once],
            '409 key in flight' => [
                self::answer(409, 'idempotency_key_in_flight'),
                409,
                'idempotency_key_in_flight',
                true,
                null,
                $sdkRetries,
            ],
            '422 suppressed recipient' => [
                self::answer(422, 'recipient_suppressed'), 422, 'recipient_suppressed', false, null, $once,
            ],
            '429 over quota' => [self::answer(429, 'quota_limited'), 429, 'quota_limited', false, null, $once],
            '429 throttled' => [
                self::answer(429, 'throttled', ['Retry-After: 5']), 429, 'throttled', true, 5, $sdkRetries,
            ],
            '503 unavailable' => [self::answer(503, 'unavailable'), 503, 'unavailable', true, null, $sdkRetries],
            'other 5xx' => [self::answer(500, 'server_error'), 500, 'server_error', false, null, $once],
            'network error' => [
                new EmailError('network_error', 'The request to PostHog failed: timeout'),
                null,
                'network_error',
                true,
                null,
                $sdkRetries,
            ],
        ];
    }

    #[DataProvider('failures')]
    public function testAFailedSendIsATransportExceptionThatSaysWhetherToRetry(
        array|EmailError $reply,
        ?int $status,
        string $code,
        bool $retryable,
        ?int $retryAfter,
        int $requests
    ): void {
        $this->reply($reply, $reply, $reply);

        try {
            $this->transport()->send(self::email());
            $this->fail('The send did not fail.');
        } catch (PostHogTransportException $error) {
            $this->assertInstanceOf(TransportExceptionInterface::class, $error);
            $this->assertSame($status, $error->getStatusCode());
            $this->assertSame($code, $error->getErrorCode());
            $this->assertSame($retryable, $error->isRetryable());
            $this->assertSame($retryAfter, $error->getRetryAfter());
            $this->assertInstanceOf(EmailError::class, $error->getPrevious());
            if ($status !== null) {
                $this->assertSame("PostHog said $code.", $error->getMessage());
                $this->assertSame('to', $error->getField());
            }
        }
        $this->assertCount($requests, $this->requests());
        $this->assertSame($this->header('Idempotency-Key'), $this->header('Idempotency-Key', $requests - 1));
    }

    public static function uploadFailures(): array
    {
        $sdkRetries = 3;
        $quota = self::answer(429, 'upload_quota_exceeded');

        return [
            'upload quota exceeded' => [[$quota], [], 429, 'upload_quota_exceeded', false, 1],
            'storage behind a failing gateway' => [
                [],
                array_fill(0, $sdkRetries, [502, '<html>Bad Gateway</html>', []]),
                502,
                'attachment_upload_failed',
                true,
                $sdkRetries,
            ],
            'storage refuses the file' => [
                [],
                [[403, '<Error><Code>AccessDenied</Code></Error>', []]],
                403,
                'attachment_upload_failed',
                false,
                1,
            ],
        ];
    }

    /**
     * @param list<array{int, string, list<string>}> $uploadReplies
     * @param list<array{int, string, list<string>}> $storageReplies
     */
    #[DataProvider('uploadFailures')]
    public function testAFailedAttachmentUploadSaysWhetherToRetryAndSendsNothing(
        array $uploadReplies,
        array $storageReplies,
        int $status,
        string $code,
        bool $retryable,
        int $attempts
    ): void {
        $this->posthog()->uploadReplies = $uploadReplies;
        $this->posthog()->storageReplies = $storageReplies;
        $email = self::email()->attach("%PDF-1.4\n", 'invoice.pdf', 'application/pdf');

        try {
            $this->transport()->send($email);
            $this->fail('The send did not fail.');
        } catch (PostHogTransportException $error) {
            $this->assertSame($status, $error->getStatusCode());
            $this->assertSame($code, $error->getErrorCode());
            $this->assertSame($retryable, $error->isRetryable());
        }
        $route = $storageReplies === [] ? 'POST' : 'PUT';
        $this->assertCount($attempts, array_filter(
            $this->requests(),
            static fn(array $request): bool => $request['method'] === $route
                && ($route === 'PUT' || str_ends_with($request['url'], '/uploads/'))
        ));
        $this->assertSame([], $this->posthog()->sends(), 'no email was sent');
        $this->assertSame([], $this->posthog()->stored);
    }

    public function testAnEmailTheSenderRefusesFailsWithoutARequest(): void
    {
        try {
            $this->transport()->send((new Email())->from('shop@example.com')->to('ana@example.com')->text('Hi'));
            $this->fail('The send did not fail.');
        } catch (PostHogTransportException $error) {
            $this->assertNull($error->getStatusCode());
            $this->assertSame('invalid_form', $error->getErrorCode());
            $this->assertSame('subject', $error->getField());
            $this->assertFalse($error->isRetryable());
        }
        $this->assertSame([], $this->requests());
    }
}
