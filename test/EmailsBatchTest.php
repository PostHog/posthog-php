<?php

namespace PostHog\Test;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\BatchEmailResult;
use PostHog\EmailBatchError;
use PostHog\EmailError;
use PostHog\Emails;
use PostHog\SendBatchResult;
use stdClass;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

class EmailsBatchTest extends TestCase
{
    use EmailsTestSupport;

    private const TEMPLATE_ID = '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b';
    private const BATCH_URL = 'https://us.posthog.com/api/messaging/send/email_batch/';
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
    private const WARNING_LOGGED = '[PostHog][Emails] Email warning sandbox_sender: Sent from the test sender.';
    private const SEND_SINGLY = "send each email the batch didn't queue with emails()->send() and its recipient's "
        . 'idempotency_key.';

    private bool $closed = false;

    /**
     * Answers each request with the next reply: an answer, an EmailError the transport throws, or a Closure
     * of the request's URL, body and timeout that returns either.
     *
     * @param list<string> $headers
     * @return array{int, string, list<string>}
     */
    public function transport(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutMs,
        int $connectTimeoutMs
    ): array {
        $at = Clock::get()->now();
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeoutMs', 'at');
        if (self::headerIn($headers, 'Authorization') !== "Bearer {$this->secretKey}") {
            return [401, '{"type":"authentication_error","code":"authentication_failed","detail":"Bad key."}', []];
        }
        if ($this->replies === []) {
            $this->fail("PostHog got a request the test has no answer for: $method $url");
        }
        $reply = array_shift($this->replies);
        if ($reply instanceof Closure) {
            $reply = $reply($url, (string) $body, $timeoutMs);
        }
        if ($reply instanceof EmailError) {
            throw $reply;
        }

        return $reply;
    }

    private function closedEmails(): Emails
    {
        return $this->emails(['isClosed' => fn(): bool => $this->closed]);
    }

    private static function queued(string $messageId, bool $replayed = false): array
    {
        return ['message_id' => $messageId, 'status' => 'queued', 'replayed' => $replayed];
    }

    private static function refused(string $messageId, string $code, int $status = 422, bool $retryable = false): array
    {
        return [
            'message_id' => $messageId,
            'error' => ['code' => $code, 'detail' => "Refused: $code.", 'status' => $status, 'retryable' => $retryable],
        ];
    }

    /** @return array{int, string, list<string>} */
    private static function ok(array ...$slots): array
    {
        return [200, json_encode(['results' => $slots]), []];
    }

    private static function allQueued(array $fields = []): Closure
    {
        return static function (string $url, string $body) use ($fields): array {
            $slots = array_map(
                static fn(array $recipient): array => self::queued('msg_' . $recipient['idempotency_key']),
                json_decode($body, true)['recipients']
            );

            return [200, json_encode(['results' => $slots, ...$fields]), []];
        };
    }

    /** @return array{int, string, list<string>} */
    private static function wholeCall(int $status, ?string $code = null): array
    {
        $body = $code === null ? '' : json_encode(['type' => 'invalid_request', 'code' => $code, 'detail' => 'Nope.']);

        return [$status, $body, []];
    }

    /** @return array<string, mixed> */
    private static function notConfirmed(?string $messageId = 'm0'): array
    {
        return array_filter([
            'message_id' => $messageId,
            'error' => [
                'code' => 'delivery_unavailable',
                'detail' => 'Not confirmed.',
                'status' => 503,
                'retryable' => true,
            ],
        ]);
    }

    /** A server that answers after this many seconds: a request with a shorter timeout gets no answer. */
    private static function slow(int $seconds, Closure $answer): Closure
    {
        return static function (string $url, string $body, int $timeoutMs) use ($seconds, $answer) {
            if ($timeoutMs < $seconds * 1000) {
                return self::networkError();
            }

            return $answer($url, $body);
        };
    }

    private static function networkError(): EmailError
    {
        return new EmailError('network_error', 'The request to PostHog failed: Connection reset.');
    }

    /** @return list<array<string, mixed>> */
    private static function recipients(int $count, array $extra = []): array
    {
        $recipients = [];
        for ($index = 0; $index < $count; $index++) {
            $recipients[] = ['to' => "user$index@example.com", 'idempotency_key' => "key-$index", ...$extra];
        }

        return $recipients;
    }

    private function batch(array $recipients, array $overrides = [], ?Emails $emails = null): SendBatchResult
    {
        return ($emails ?? $this->emails())->batch(
            ['recipients' => $recipients, 'template_id' => self::TEMPLATE_ID, ...$overrides]
        );
    }

    private function batchError(array $recipients, array $overrides = [], ?Emails $emails = null): EmailBatchError
    {
        try {
            $this->batch($recipients, $overrides, $emails);
        } catch (EmailBatchError $error) {
            return $error;
        }
        $this->fail('batch() did not throw EmailBatchError');
    }

    private function refusal(array $batch, ?Emails $emails = null): EmailError
    {
        try {
            ($emails ?? $this->emails())->batch($batch);
        } catch (EmailError $error) {
            return $error;
        }
        $this->fail('batch() did not throw EmailError');
    }

    /** @return list<array<string, mixed>> */
    private function batchBodies(): array
    {
        $bodies = [];
        foreach ($this->requests as $index => $request) {
            if ($request['url'] === self::BATCH_URL) {
                $bodies[] = $this->body($index);
            }
        }

        return $bodies;
    }

    /** @return list<int> */
    private function recipientsPerRequest(): array
    {
        return array_map(static fn(array $body): int => count($body['recipients']), $this->batchBodies());
    }

    /** @return list<string> */
    private function requestUrls(): array
    {
        return array_column($this->requests, 'url');
    }

    /** @return list<string> */
    private static function times(int $count, string $status): array
    {
        return array_fill(0, $count, $status);
    }

    /**
     * @param list<BatchEmailResult> $results
     * @return list<string>
     */
    private static function statuses(array $results): array
    {
        return array_map(static fn(BatchEmailResult $result): string => $result->getStatus(), $results);
    }

    public function testATemplateBatchPostsOneRequestWithAResultPerRecipient(): void
    {
        $this->reply(self::ok(
            self::queued('m0'),
            self::queued('m1', true),
            self::refused('m2', 'recipient_suppressed')
        ));

        $result = $this->batch([
            ['to' => 'a@example.com', 'idempotency_key' => 'welcome:a', 'distinct_id' => 'a'],
            ['to' => 'b@example.com', 'template_data' => ['name' => 'Bea'], 'distinct_id' => null],
            ['to' => 'c@example.com', 'idempotency_key' => 'welcome:c'],
        ], ['template_data' => ['name' => 'friend', 'plan' => 'pro']]);

        $this->assertCount(1, $this->requests);
        $this->assertSame(['POST', self::BATCH_URL], [$this->requests[0]['method'], $this->requests[0]['url']]);
        $this->assertSame('Bearer phs_secret', $this->header('Authorization'));
        $this->assertSame('application/json', $this->header('Content-Type'));
        $this->assertNull($this->header('Idempotency-Key'));
        $body = $this->body();
        $generated = $body['recipients'][1]['idempotency_key'];
        $this->assertMatchesRegularExpression(self::UUID_V4, $generated);
        $this->assertEquals([
            'template_id' => self::TEMPLATE_ID,
            'message_data' => ['name' => 'friend', 'plan' => 'pro'],
            'recipients' => [
                ['to' => 'a@example.com', 'idempotency_key' => 'welcome:a', 'distinct_id' => 'a'],
                ['to' => 'b@example.com', 'idempotency_key' => $generated, 'message_data' => ['name' => 'Bea']],
                ['to' => 'c@example.com', 'idempotency_key' => 'welcome:c'],
            ],
        ], $body);
        $results = $result->getResults();
        $this->assertSame([0, 1, 2], array_map(static fn($r): int => $r->getIndex(), $results));
        $this->assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com'],
            array_map(static fn($r): string => $r->getTo(), $results)
        );
        $this->assertSame(
            ['welcome:a', $generated, 'welcome:c'],
            array_map(static fn($r): string => $r->getIdempotencyKey(), $results)
        );
        $this->assertSame(
            [['queued', 'm0', false], ['queued', 'm1', true], ['failed', 'm2', false]],
            array_map(static fn($r): array => [$r->getStatus(), $r->getMessageId(), $r->isReplayed()], $results)
        );
        $this->assertNull($results[0]->getError());
        $error = $results[2]->getError();
        $this->assertSame(
            ['recipient_suppressed', 422, 'm2', 'Refused: recipient_suppressed.', 'validation_error'],
            [
                $error->getErrorCode(),
                $error->getStatusCode(),
                $error->getMessageId(),
                $error->getMessage(),
                $error->getErrorName(),
            ]
        );
        $this->assertSame([], $result->getWarnings());
    }

    public function testATemplateBatchWithoutDataSendsAnEmptyObject(): void
    {
        $this->reply(self::allQueued());

        $this->batch([['to' => 'a@example.com', 'idempotency_key' => 'k', 'template_data' => []]]);

        $body = json_decode($this->requests[0]['body'], flags: JSON_THROW_ON_ERROR);
        $this->assertEquals(new stdClass(), $body->message_data);
        $this->assertEquals(new stdClass(), $body->recipients[0]->message_data);
    }

    public function testACodeBatchSendsTheSameContentToEveryRecipient(): void
    {
        $this->reply(self::allQueued());

        $result = $this->emails()->batch([
            'recipients' => self::recipients(2),
            'template_key' => 'launch-news',
            'subject' => 'We launched',
            'html' => '<p>Hi</p>',
            'from' => 'Acme <news@example.com>',
            'reply_to' => 'support@example.com',
            'tracking' => false,
            'sensitive' => null,
        ]);

        $this->assertEquals([
            'template_key' => 'launch-news',
            'subject' => 'We launched',
            'html' => '<p>Hi</p>',
            'from' => 'Acme <news@example.com>',
            'reply_to' => ['support@example.com'],
            'tracking' => false,
            'recipients' => [
                ['to' => 'user0@example.com', 'idempotency_key' => 'key-0'],
                ['to' => 'user1@example.com', 'idempotency_key' => 'key-1'],
            ],
        ], $this->body());
        $this->assertSame(['queued', 'queued'], self::statuses($result->getResults()));
    }

    public static function batchRefusals(): array
    {
        $code = ['template_key' => 'k', 'subject' => 's', 'html' => 'h'];

        return [
            'scheduled_at' => [['scheduled_at' => '2026-12-01T09:00:00Z'], 'invalid_form', 'scheduled_at'],
            'cc' => [[...$code, 'cc' => 'x@example.com'], 'invalid_form', 'cc'],
            'bcc' => [[...$code, 'bcc' => 'x@example.com'], 'invalid_form', 'bcc'],
            'attachments' => [[...$code, 'attachments' => []], 'invalid_form', 'attachments'],
            'to on the batch' => [['to' => 'a@example.com'], 'invalid_form', 'to'],
            'idempotency_key on the batch' => [['idempotency_key' => 'k'], 'invalid_form', 'idempotency_key'],
            'unknown field' => [['colour' => 'blue'], 'invalid_form', 'colour'],
            'no recipients' => [['recipients' => []], 'invalid_recipients', 'recipients'],
            'recipients left out' => [['recipients' => null], 'invalid_recipients', 'recipients'],
            'recipients not a list' => [['recipients' => 'a@example.com'], 'invalid_recipients', 'recipients'],
            'recipient not an array' => [['recipients' => ['a@example.com']], 'invalid_recipient', 'recipients__0'],
            'recipient without to' => [
                ['recipients' => [['distinct_id' => 'a']]],
                'invalid_recipient',
                'recipients__0__to',
            ],
            'recipient with a blank to' => [
                ['recipients' => [['to' => ' ']]],
                'invalid_recipient',
                'recipients__0__to',
            ],
            'recipient with an unknown field' => [
                ['recipients' => [['to' => 'a@example.com', 'cc' => 'b@example.com']]],
                'invalid_form',
                'recipients__0__cc',
            ],
            'recipient with an empty distinct_id' => [
                ['recipients' => [['to' => 'a@example.com', 'distinct_id' => '']]],
                'invalid_distinct_id',
                'recipients__0__distinct_id',
            ],
            'recipient with a bad key' => [
                ['recipients' => [['to' => 'a@example.com', 'idempotency_key' => 'has space']]],
                'invalid_idempotency_key',
                'recipients__0__idempotency_key',
            ],
            'recipient with a list as template_data' => [
                ['recipients' => [['to' => 'a@example.com', 'template_data' => [1]]]],
                'invalid_template_data',
                'recipients__0__template_data',
            ],
            'code form recipient with template_data' => [
                [...$code, 'recipients' => [['to' => 'a@example.com', 'template_data' => ['a' => 1]]]],
                'invalid_form',
                'recipients__0__template_data',
            ],
            'both forms' => [[...$code, 'template_id' => self::TEMPLATE_ID], 'invalid_form', null],
            'neither form' => [['template_id' => null], 'invalid_form', null],
            'bad template_id' => [['template_id' => 'welcome'], 'invalid_template_id', 'template_id'],
            'bad shared template_data' => [['template_data' => 'Ada'], 'invalid_template_data', 'template_data'],
            'code form without content' => [['template_key' => 'k', 'subject' => 's'], 'invalid_form', 'html'],
            'bad max_retries' => [['max_retries' => -1], 'invalid_max_retries', 'max_retries'],
        ];
    }

    #[DataProvider('batchRefusals')]
    public function testRefusesWhatABatchDoesNotTakeBeforeAnyRequest(
        array $overrides,
        string $code,
        ?string $field
    ): void {
        $batch = ['recipients' => self::recipients(1), 'template_id' => self::TEMPLATE_ID, ...$overrides];
        if (array_key_exists('template_key', $overrides) && !array_key_exists('template_id', $overrides)) {
            unset($batch['template_id']);
        }

        $error = $this->refusal($batch);

        $this->assertNotInstanceOf(EmailBatchError::class, $error);
        $this->assertSame([$code, $field], [$error->getErrorCode(), $error->getField()]);
        $this->assertSame('validation_error', $error->getErrorName());
        $this->assertSame([], $this->requests);
    }

    public function testACodeBatchWithoutContentSaysWhatABatchNeeds(): void
    {
        $error = $this->refusal(['template_key' => 'digest', 'subject' => 'Hi', 'recipients' => self::recipients(1)]);

        $this->assertSame('A batch with template_key needs a nonempty html, text or both.', $error->getMessage());
    }

    public function testNamesBothPositionsOfARepeatedIdempotencyKey(): void
    {
        $recipients = self::recipients(3);
        $recipients[2]['idempotency_key'] = 'key-0';

        $error = $this->refusal(['recipients' => $recipients, 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame('invalid_idempotency_key', $error->getErrorCode());
        $this->assertSame('recipients__2__idempotency_key', $error->getField());
        $this->assertStringContainsString(
            'recipients[2] has the same idempotency_key as recipients[0]',
            $error->getMessage()
        );
        $this->assertSame([], $this->requests);
    }

    public function testNamesThePositionOfABadRecipient(): void
    {
        $recipients = self::recipients(3);
        $recipients[1]['to'] = '';

        $error = $this->refusal(['recipients' => $recipients, 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame('recipients__1__to', $error->getField());
        $this->assertStringStartsWith('recipients[1]: ', $error->getMessage());
    }

    public function testMoreThan100RecipientsAreRefusedBeforeAnyRequest(): void
    {
        $error = $this->refusal(['recipients' => self::recipients(101), 'template_id' => self::TEMPLATE_ID]);

        $this->assertNotInstanceOf(EmailBatchError::class, $error);
        $this->assertSame(
            ['invalid_recipients', 'recipients', 'validation_error'],
            [$error->getErrorCode(), $error->getField(), $error->getErrorName()]
        );
        $this->assertSame('recipients takes at most 100 recipients, so no email was sent.', $error->getMessage());
        $this->assertSame([], $this->requests);
    }

    public function testABatchTooLargeForPostHogIsTheErrorAndSaysToSplitIt(): void
    {
        $this->reply(self::wholeCall(413, 'too_large'));

        $error = $this->batchError(self::recipients(100), ['max_retries' => 2]);

        $this->assertSame(['too_large', 413, 'Nope.'], [
            $error->getErrorCode(),
            $error->getStatusCode(),
            $error->getMessage(),
        ]);
        $this->assertSame('Split the recipients into smaller batches.', $error->getFix());
        $this->assertSame(self::times(100, 'failed'), self::statuses($error->getResults()));
        $this->assertCount(1, $this->requests);
    }

    public function testAHundredRecipientsGoOutInOneRequest(): void
    {
        $this->reply(self::allQueued());

        $result = $this->batch(self::recipients(100));

        $this->assertSame([100], $this->recipientsPerRequest());
        $this->assertSame(self::times(100, 'queued'), self::statuses($result->getResults()));
    }

    public function testARecipientIsEncodedWithoutExtraCopiesOfItsData(): void
    {
        $script = <<<'PHP'
<?php
require $argv[1];
$appMemory = str_repeat('b', 14 * 1024 * 1024);
$emails = new PostHog\Emails('phs_secret', 'us.i.posthog.com', transport: static fn(): array => [
    200,
    '{"results":[{"error":{"code":"content_too_large","detail":"Too large.","status":413}}]}',
    [],
]);
$result = $emails->batch([
    'recipients' => [['to' => 'user@example.com', 'template_data' => ['note' => str_repeat('x', 3950000)]]],
    'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b',
]);
echo $result->getResults()[0]->getStatus(), ' ', $result->getResults()[0]->getError()->getErrorCode();
PHP;

        $this->assertSame([0, 'failed content_too_large'], self::runWithMemoryLimit($script, '32M'));
    }

    public function testARecipientNestedDeeperThanItsEncodingAllowsIsRefusedBeforeAnyRequest(): void
    {
        $nested = 'v';
        for ($level = 1; $level < 512; $level++) {
            $nested = [$nested];
        }

        try {
            $this->batch([
                ...self::recipients(1),
                ['to' => 'deep@example.com', 'idempotency_key' => 'key-deep', 'template_data' => ['deep' => $nested]],
            ]);
            $this->fail('Expected invalid_form');
        } catch (EmailError $error) {
            $this->assertSame('invalid_form', $error->getErrorCode());
        }
        $this->assertSame([], $this->requests);
    }

    public function testARecipientWithoutToIsRefusedBeforeSharedContentIsEncoded(): void
    {
        $script = <<<'PHP'
<?php
require $argv[1];
$emails = new PostHog\Emails('phs_secret', 'us.i.posthog.com', transport: static fn(): array => [500, '', []]);
try {
    $emails->batch([
        'recipients' => [['distinct_id' => 'user-1']],
        'template_key' => 'news',
        'subject' => 'News',
        'html' => str_repeat("\0", 21 * 1024 * 1024),
    ]);
} catch (PostHog\EmailError $error) {
    echo json_encode([$error->getErrorCode(), $error->getField()]);
}
PHP;

        $this->assertSame([0, '["invalid_recipient","recipients__0__to"]'], self::runWithMemoryLimit($script, '128M'));
    }

    /**
     * @return array{int, string}
     */
    private static function runWithMemoryLimit(string $code, string $limit): array
    {
        $script = tempnam(sys_get_temp_dir(), 'posthog-emails-batch-');
        file_put_contents($script, $code);
        try {
            $command = escapeshellarg(PHP_BINARY) . " -d memory_limit=$limit " . escapeshellarg($script) . ' '
                . escapeshellarg(__DIR__ . '/../vendor/autoload.php') . ' 2>&1';
            exec($command, $output, $exitCode);

            return [$exitCode, implode("\n", $output)];
        } finally {
            unlink($script);
        }
    }

    public function testABatchWaitsForASlowAnswerWhateverTheClientTimeout(): void
    {
        $this->reply(self::slow(40, self::allQueued()));

        $result = $this->batch(self::recipients(1), emails: $this->emails(['requestTimeoutMs' => 3000]));

        $this->assertSame(['queued'], self::statuses($result->getResults()));
    }

    public function testALongerClientTimeoutAppliesToABatchToo(): void
    {
        $this->reply(self::slow(50, self::allQueued()));

        $result = $this->batch(self::recipients(1), emails: $this->emails(['requestTimeoutMs' => 60000]));

        $this->assertSame(['queued'], self::statuses($result->getResults()));
    }

    public function testARetryAfterALostAnswerReportsWhatTheRetryAnswered(): void
    {
        $this->reply(
            self::networkError(),
            self::ok(self::queued('m0', true), self::refused('m1', 'idempotency_key_reused'))
        );
        $recipients = self::recipients(2);
        unset($recipients[1]['idempotency_key']);

        $result = $this->batch($recipients);

        $this->assertCount(2, $this->requests);
        $this->assertSame($this->body(0), $this->body(1));
        $derived = $this->body(1)['recipients'][1]['idempotency_key'];
        $this->assertMatchesRegularExpression(self::UUID_V4, $derived);
        [$first, $second] = $result->getResults();
        $this->assertSame($derived, $second->getIdempotencyKey());
        $this->assertSame(['queued', 'm0', true], [$first->getStatus(), $first->getMessageId(), $first->isReplayed()]);
        $this->assertSame(['failed', 'm1'], [$second->getStatus(), $second->getMessageId()]);
        $this->assertSame('idempotency_key_reused', $second->getError()->getErrorCode());
    }

    public function testAnAnswerTooLargeToReadLeavesTheRequestUnconfirmed(): void
    {
        $this->reply(
            new EmailError('response_too_large', 'PostHog answered with more than 1048576 bytes.')
        );

        $error = $this->batchError(self::recipients(1));

        $this->assertSame('response_too_large', $error->getErrorCode());
        $this->assertSame(['unconfirmed'], self::statuses($error->getResults()));
        $this->assertSame([self::BATCH_URL], $this->requestUrls());
    }

    public function testA503ThenARefusalOfTheWholeRequestKeepsTheRecipientUnconfirmed(): void
    {
        $this->reply(self::wholeCall(503, 'delivery_unavailable'), self::wholeCall(422, 'sending_paused'));

        $error = $this->batchError(self::recipients(1));

        $this->assertSame(['sending_paused', 422], [$error->getErrorCode(), $error->getStatusCode()]);
        [$result] = $error->getResults();
        $this->assertSame('unconfirmed', $result->getStatus());
        $this->assertSame('delivery_unavailable', $result->getError()->getErrorCode());
    }

    public function testARecipientThePipelineDidNotConfirmIsUnconfirmed(): void
    {
        $this->reply(self::ok(self::notConfirmed()));

        [$result] = $this->batch(self::recipients(1))->getResults();

        $this->assertSame(['unconfirmed', 'm0'], [$result->getStatus(), $result->getMessageId()]);
        $this->assertSame(['delivery_unavailable', 503], [
            $result->getError()->getErrorCode(),
            $result->getError()->getStatusCode(),
        ]);
        $this->assertSame('key-0', $result->getIdempotencyKey());
    }

    public function testABatchThatSucceedsReadsNothingBackForAnUnconfirmedEmail(): void
    {
        $this->reply(self::ok(self::queued('m0'), self::notConfirmed(null), self::notConfirmed('m2')));

        $result = $this->batch(self::recipients(3), ['max_retries' => 0]);

        $this->assertSame([self::BATCH_URL], $this->requestUrls());
        $this->assertSame(
            [['queued', 'm0', 'key-0'], ['unconfirmed', null, 'key-1'], ['unconfirmed', 'm2', 'key-2']],
            array_map(
                static fn($r): array => [$r->getStatus(), $r->getMessageId(), $r->getIdempotencyKey()],
                $result->getResults()
            )
        );
    }

    public function testAWholeRequestInFlightAnswerLeavesItsRecipientsUnconfirmed(): void
    {
        $this->reply(self::wholeCall(409, 'idempotency_key_in_flight'));

        $error = $this->batchError(self::recipients(2), ['max_retries' => 0]);

        $this->assertSame('idempotency_key_in_flight', $error->getErrorCode());
        $this->assertSame(
            [['unconfirmed', null], ['unconfirmed', null]],
            array_map(static fn($r): array => [$r->getStatus(), $r->getMessageId()], $error->getResults())
        );
        $recipientError = $error->getResults()[0]->getError();
        $this->assertSame(
            [
                'idempotency_key_in_flight',
                'Nope.',
                'Send it again with the same idempotency_key within 24 hours, so it goes out once.',
            ],
            [$recipientError->getErrorCode(), $recipientError->getMessage(), $recipientError->getFix()]
        );
        $this->assertSame([self::BATCH_URL], $this->requestUrls());
    }

    public static function attemptsAfterAnInFlightAnswer(): array
    {
        return [
            'a refusal after an in-flight answer' => [
                [self::wholeCall(409, 'idempotency_key_in_flight'), self::wholeCall(429, 'quota_limited')],
                'quota_limited',
            ],
            'an in-flight answer after a 503' => [
                [self::wholeCall(503, 'delivery_unavailable'), self::wholeCall(409, 'idempotency_key_in_flight')],
                'idempotency_key_in_flight',
            ],
        ];
    }

    #[DataProvider('attemptsAfterAnInFlightAnswer')]
    public function testAnAttemptThatMayHaveQueuedKeepsItsRecipientsUnconfirmedWhateverTheNextAnswers(
        array $answers,
        string $code
    ): void {
        $this->reply(...$answers);

        $error = $this->batchError(self::recipients(1), ['max_retries' => 1]);

        $this->assertSame($code, $error->getErrorCode());
        $this->assertSame(['unconfirmed'], self::statuses($error->getResults()));
        $this->assertSame([self::BATCH_URL, self::BATCH_URL], $this->requestUrls());
    }

    public function testAShutdownDuringARetryWaitSendsNoRetry(): void
    {
        Clock::set(self::clockThatShutsDownOnSleep($this->clock, function (): void {
            $this->closed = true;
        }));
        $this->reply(self::wholeCall(429), self::allQueued());

        $error = $this->batchError(self::recipients(2), emails: $this->closedEmails());

        $this->assertSame('client_closed', $error->getErrorCode());
        $this->assertCount(1, $this->requests);
        $this->assertSame(['failed', 'failed'], self::statuses($error->getResults()));
        $this->assertSame('client_closed', $error->getResults()[0]->getError()->getErrorCode());
    }

    private static function clockThatShutsDownOnSleep(MockClock $clock, Closure $shutDown): ClockInterface
    {
        return new class ($clock, $shutDown) implements ClockInterface {
            public function __construct(private MockClock $clock, private Closure $shutDown)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->clock->now();
            }

            public function sleep(float|int $seconds): void
            {
                ($this->shutDown)();
                $this->clock->sleep($seconds);
            }

            public function withTimeZone(DateTimeZone|string $timezone): static
            {
                return $this;
            }
        };
    }

    public function testARequestRefusedAsAWholeThrowsWithEveryRecipient(): void
    {
        $this->reply(self::wholeCall(429, 'quota_limited'));

        $error = $this->batchError(self::recipients(3));

        $this->assertSame(
            ['quota_limited', 429, 'Nope.'],
            [$error->getErrorCode(), $error->getStatusCode(), $error->getMessage()]
        );
        $this->assertCount(1, $this->requests);
        $results = $error->getResults();
        $this->assertSame(self::times(3, 'failed'), self::statuses($results));
        $this->assertSame('quota_limited', $results[1]->getError()->getErrorCode());
        $this->assertSame('key-2', $results[2]->getIdempotencyKey());
    }

    public function testARefusalOfTheWholeRequestAfterALostAnswerKeepsItsRecipientsUnconfirmed(): void
    {
        $this->reply(self::networkError(), self::wholeCall(429, 'quota_limited'));

        $error = $this->batchError(self::recipients(1));

        [$result] = $error->getResults();
        $this->assertSame(['unconfirmed', null], [$result->getStatus(), $result->getMessageId()]);
        $this->assertSame('delivery_unavailable', $result->getError()->getErrorCode());
        $this->assertSame([self::BATCH_URL, self::BATCH_URL], $this->requestUrls());
    }

    public function testALostAnswerAfterTheRetriesTellsEachRecipientToSendAgainWithItsKey(): void
    {
        $this->reply(self::networkError(), self::networkError(), self::networkError());

        $error = $this->batchError(self::recipients(2));

        $this->assertSame('network_error', $error->getErrorCode());
        $this->assertSame([self::BATCH_URL, self::BATCH_URL, self::BATCH_URL], $this->requestUrls());
        $this->assertSame(
            [['unconfirmed', null, 'key-0'], ['unconfirmed', null, 'key-1']],
            array_map(
                static fn($r): array => [$r->getStatus(), $r->getMessageId(), $r->getIdempotencyKey()],
                $error->getResults()
            )
        );
        $this->assertSame(
            "PostHog didn't confirm this email, so it may still go out. Send it again with the same idempotency_key "
                . 'within 24 hours, so it goes out once.',
            $error->getResults()[1]->getError()->getMessage()
        );
    }

    public function testAnInFlightCodeOnAnAnswerOtherThanA409RefusesTheRequest(): void
    {
        $this->reply(self::wholeCall(400, 'idempotency_key_in_flight'));

        $error = $this->batchError(self::recipients(2), ['max_retries' => 2]);

        $this->assertCount(1, $this->requests);
        $this->assertSame(['failed', 'failed'], self::statuses($error->getResults()));
    }

    public function testBatchUnavailableIsNotRetriedAndSaysToSendEachEmailOnItsOwn(): void
    {
        $this->reply(self::wholeCall(409, 'batch_unavailable'));

        $error = $this->batchError(self::recipients(2), ['max_retries' => 2]);

        $this->assertCount(1, $this->requests);
        $this->assertSame('batch_unavailable', $error->getErrorCode());
        $this->assertSame('Until batch sends are on for the project, ' . self::SEND_SINGLY, $error->getFix());
        $this->assertSame(['failed', 'failed'], self::statuses($error->getResults()));
    }

    public static function notFoundAnswers(): array
    {
        return [
            'empty body' => ['', 'PostHog found no batch send for this request, so no email was sent.'],
            "PostHog's answer" => ['{"type":"invalid_request","code":"not_found","detail":"Not found."}', 'Not found.'],
        ];
    }

    #[DataProvider('notFoundAnswers')]
    public function testA404SaysBatchSendsAreUnavailable(string $body, string $message): void
    {
        $this->reply([404, $body, []]);

        $error = $this->batchError(self::recipients(1));

        $this->assertSame(['not_found', $message], [$error->getErrorCode(), $error->getMessage()]);
        $this->assertStringContainsString('batch sends', strtolower((string) $error->getWhy()));
        $this->assertSame('Check template_id, or ' . self::SEND_SINGLY, $error->getFix());
    }

    public static function retriesAfterAnUnconfirmingAttempt(): array
    {
        $unavailable = self::wholeCall(503, 'delivery_unavailable');

        return [
            'a 503, then an answer without a result per recipient' => [$unavailable, self::ok(), 'api_error'],
            'a lost answer, then an answer without a result per recipient' => [
                self::networkError(),
                self::ok(),
                'api_error',
            ],
            'a 503, then a 503' => [$unavailable, $unavailable, 'delivery_unavailable'],
        ];
    }

    #[DataProvider('retriesAfterAnUnconfirmingAttempt')]
    public function testARetryThatCannotConfirmEitherLeavesTheRecipientUnconfirmed(
        mixed $first,
        mixed $retry,
        string $code
    ): void {
        $this->reply($first, $retry);

        $error = $this->batchError(self::recipients(1), ['max_retries' => 1]);

        $this->assertSame($code, $error->getErrorCode());
        [$result] = $error->getResults();
        $this->assertSame('unconfirmed', $result->getStatus());
        $this->assertSame('delivery_unavailable', $result->getError()->getErrorCode());
    }

    public function testAnAnswerWithoutAResultPerRecipientLeavesTheRequestUnconfirmed(): void
    {
        $this->reply(self::ok(self::queued('m0')));

        $error = $this->batchError(self::recipients(2), ['max_retries' => 0]);

        $this->assertSame('api_error', $error->getErrorCode());
        $this->assertSame(['unconfirmed', 'unconfirmed'], self::statuses($error->getResults()));
        $this->assertSame([self::BATCH_URL], $this->requestUrls());
    }

    public function testWarningsReachTheCallerOnce(): void
    {
        global $errorMessages;
        $warning = ['code' => 'sandbox_sender', 'detail' => 'Sent from the test sender.'];
        $this->reply(self::allQueued(['warnings' => [$warning]]));

        $result = $this->batch(self::recipients(2));

        $codes = array_map(static fn($warning): string => $warning->getCode(), $result->getWarnings());
        $this->assertSame(['sandbox_sender'], $codes);
        $this->assertSame([self::WARNING_LOGGED], $errorMessages);
    }

    public static function setupErrors(): array
    {
        return [
            'disabled' => [['isDisabled' => static fn(): bool => true], 'client_disabled'],
            'shut down' => [['isClosed' => static fn(): bool => true], 'client_closed'],
            'off Cloud without an app host' => [['host' => 'https://ph.example.com'], 'missing_app_host'],
            'no secret key' => [['secretKey' => ''], 'missing_secret_key'],
        ];
    }

    #[DataProvider('setupErrors')]
    public function testAClientThatCannotSendSendsNothing(array $config, string $code): void
    {
        $error = $this->refusal(
            ['recipients' => self::recipients(1), 'template_id' => self::TEMPLATE_ID],
            $this->emails($config)
        );

        $this->assertNotInstanceOf(EmailBatchError::class, $error);
        $this->assertSame($code, $error->getErrorCode());
        $this->assertSame([], $this->requests);
    }
}
