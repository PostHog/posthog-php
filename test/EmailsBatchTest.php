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
use JsonSerializable;
use RuntimeException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

class EmailsBatchTest extends TestCase
{
    use EmailsTestSupport;

    private const TEMPLATE_ID = '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b';
    private const BATCH_URL = 'https://us.posthog.com/api/messaging/send/email_batch/';
    private const FOUR_MIB = 4 * 1024 * 1024;
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
        $this->assertNull($results[2]->getLaterError());
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

    public function testSplitsBy100RecipientsAndSendsTheRequestsInOrder(): void
    {
        $this->reply(self::allQueued(), self::allQueued(), self::allQueued());

        $result = $this->batch(self::recipients(250));

        $this->assertSame([100, 100, 50], $this->recipientsPerRequest());
        $results = $result->getResults();
        $this->assertSame(range(0, 249), array_map(static fn($r): int => $r->getIndex(), $results));
        $this->assertSame(
            array_map(static fn(int $i): string => "msg_key-$i", range(0, 249)),
            array_map(static fn($r): ?string => $r->getMessageId(), $results)
        );
        $this->assertSame(self::times(250, 'queued'), self::statuses($results));
    }

    public function testSplitsByThe4MibBodyAndFailsOnlyARecipientTooLargeAlone(): void
    {
        $recipients = self::recipients(5, ['template_data' => ['note' => str_repeat('x', intdiv(self::FOUR_MIB, 3))]]);
        $recipients[3]['template_data'] = ['note' => str_repeat('y', self::FOUR_MIB)];
        $this->reply(self::allQueued(), self::allQueued());

        $result = $this->batch($recipients);

        foreach ($this->requests as $request) {
            $this->assertLessThanOrEqual(self::FOUR_MIB, strlen($request['body']));
        }
        $this->assertSame(
            [['key-0', 'key-1'], ['key-2', 'key-4']],
            array_map(
                static fn(array $body): array => array_column($body['recipients'], 'idempotency_key'),
                $this->batchBodies()
            )
        );
        $results = $result->getResults();
        $this->assertSame(['queued', 'queued', 'queued', 'failed', 'queued'], self::statuses($results));
        $tooLarge = $results[3];
        $this->assertSame(['content_too_large', 'recipients__3__template_data', 'validation_error'], [
            $tooLarge->getError()->getErrorCode(),
            $tooLarge->getError()->getField(),
            $tooLarge->getError()->getErrorName(),
        ]);
        $this->assertSame([null, 'key-3'], [$tooLarge->getMessageId(), $tooLarge->getIdempotencyKey()]);
    }

    public static function sharedContentOver4Mib(): array
    {
        $over = str_repeat('x', self::FOUR_MIB);

        return [
            'html' => [['template_key' => 'news', 'subject' => 'News', 'html' => $over, 'text' => 'Hi'], 'html'],
            'text' => [['template_key' => 'news', 'subject' => 'News', 'html' => '<p>Hi</p>', 'text' => $over], 'text'],
            'from' => [['template_key' => 'news', 'subject' => 'News', 'html' => '<p>Hi</p>', 'from' => $over], 'from'],
            'reply_to' => [
                ['template_key' => 'news', 'subject' => 'News', 'html' => '<p>Hi</p>', 'reply_to' => $over],
                'reply_to',
            ],
            'template_data' => [
                ['template_id' => self::TEMPLATE_ID, 'template_data' => ['note' => $over]],
                'template_data',
            ],
        ];
    }

    #[DataProvider('sharedContentOver4Mib')]
    public function testSharedContentOver4MibIsRefusedBeforeAnyRequest(array $content, string $field): void
    {
        $error = $this->refusal(['recipients' => self::recipients(2), ...$content]);

        $this->assertNotInstanceOf(EmailBatchError::class, $error);
        $this->assertSame(['content_too_large', $field, 'validation_error'], [
            $error->getErrorCode(),
            $error->getField(),
            $error->getErrorName(),
        ]);
        $this->assertSame([], $this->requests);
    }

    public function testSharedContentOver4MibOnlyTogetherNamesItsLargestPart(): void
    {
        $half = intdiv(self::FOUR_MIB, 2) + 1024;

        $error = $this->refusal([
            'recipients' => self::recipients(1),
            'template_key' => 'news',
            'subject' => 'News',
            'html' => str_repeat('h', $half + 1),
            'text' => str_repeat('t', $half),
        ]);

        $this->assertSame([
            'content_too_large',
            'html',
            'The email every recipient of the batch gets is larger than one batch request takes (4 MiB of JSON), so '
                . 'no email was sent. Its largest part is html.',
        ], [$error->getErrorCode(), $error->getField(), $error->getMessage()]);
        $this->assertSame([], $this->requests);
    }

    public static function recipientsTooLargeAlone(): array
    {
        return [
            'a large distinct_id' => [self::FOUR_MIB, 2, 'recipients__0__distinct_id'],
            'large template_data' => [6, self::FOUR_MIB, 'recipients__0__template_data'],
            'a distinct_id a little larger than template_data' => [2097130, 2097115, 'recipients__0__distinct_id'],
            'template_data a little larger than distinct_id' => [2097115, 2097130, 'recipients__0__template_data'],
        ];
    }

    #[DataProvider('recipientsTooLargeAlone')]
    public function testARecipientTooLargeAloneNamesItsLargestField(
        int $distinctIdBytes,
        int $noteBytes,
        string $field
    ): void {
        $result = $this->batch([[
            'to' => 'a@example.com',
            'idempotency_key' => 'k1',
            'distinct_id' => str_repeat('d', $distinctIdBytes),
            'template_data' => ['x' => str_repeat('x', $noteBytes)],
        ]]);

        $recipient = $result->getResults()[0];
        $this->assertSame(
            ['failed', 'content_too_large', $field],
            [$recipient->getStatus(), $recipient->getError()->getErrorCode(), $recipient->getError()->getField()]
        );
        $this->assertSame([], $this->requests);
    }

    public static function sharedContentAtTheLimit(): array
    {
        return [
            'exactly 4 MiB' => [0, 'recipient failed with content_too_large'],
            'one byte more' => [1, 'batch throws content_too_large for template_data'],
        ];
    }

    #[DataProvider('sharedContentAtTheLimit')]
    public function testSharedContentTakesUpToExactly4MibOfRequest(int $bytesOver, string $outcome): void
    {
        $empty = ['template_id' => self::TEMPLATE_ID, 'message_data' => ['n' => ''], 'recipients' => []];
        $note = str_repeat('a', self::FOUR_MIB - strlen(json_encode($empty)) + $bytesOver);

        try {
            $result = $this->batch(self::recipients(1), ['template_data' => ['n' => $note]]);
            $recipient = $result->getResults()[0];
            $actual = "recipient {$recipient->getStatus()} with {$recipient->getError()->getErrorCode()}";
        } catch (EmailError $error) {
            $actual = "batch throws {$error->getErrorCode()} for {$error->getField()}";
        }

        $this->assertSame([$outcome, []], [$actual, $this->requests]);
    }

    public static function valuesThatChangeOnASecondEncoding(): array
    {
        return [
            'INF the second time' => [static fn(): float => INF],
            'an exception the second time' => [static function (): never {
                throw new RuntimeException('Serialized twice.');
            }],
        ];
    }

    #[DataProvider('valuesThatChangeOnASecondEncoding')]
    public function testSharedContentOver4MibIsDiagnosedFromItsOnlyEncoding(Closure $secondTime): void
    {
        $note = new class (str_repeat('x', self::FOUR_MIB), $secondTime) implements JsonSerializable {
            private int $encodings = 0;

            public function __construct(private string $first, private Closure $secondTime)
            {
            }

            public function jsonSerialize(): mixed
            {
                return $this->encodings++ === 0 ? $this->first : ($this->secondTime)();
            }
        };

        $error = $this->refusal([
            'recipients' => self::recipients(1),
            'template_id' => self::TEMPLATE_ID,
            'template_data' => ['note' => $note],
        ]);

        $this->assertSame(['content_too_large', 'template_data'], [$error->getErrorCode(), $error->getField()]);
        $this->assertSame([], $this->requests);
    }

    public function testABatchOf10000RecipientsFitsIn24MibOfMemory(): void
    {
        $script = <<<'PHP'
<?php
require $argv[1];
$requests = 0;
$emails = new PostHog\Emails('phs_secret', 'us.i.posthog.com', transport: static function (
    string $method,
    string $url,
    array $headers,
    ?string $body
) use (&$requests): array {
    $requests++;
    $slots = [];
    foreach (json_decode($body, true)['recipients'] as $recipient) {
        $slots[] = ['message_id' => 'msg_' . $recipient['idempotency_key'], 'status' => 'queued'];
    }

    return [200, json_encode(['results' => $slots]), []];
});
$recipients = [];
for ($index = 0; $index < 10000; $index++) {
    $recipients[] = ['to' => "user$index@example.com", 'idempotency_key' => "key-$index"];
}
$result = $emails->batch([
    'recipients' => $recipients,
    'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b',
    'template_data' => ['note' => str_repeat('x', 150000)],
]);
$queued = count(array_filter($result->getResults(), static fn($email): bool => $email->getStatus() === 'queued'));
echo json_encode([$requests, $queued]);
PHP;
        $this->assertSame([0, '[100,10000]'], self::runWithMemoryLimit($script, '24M'));
    }

    public function testARecipientWithA10MibDistinctIdOfNulBytesFailsWithin128MibOfMemory(): void
    {
        $script = <<<'PHP'
<?php
require $argv[1];
$emails = new PostHog\Emails('phs_secret', 'us.i.posthog.com', transport: static fn(): array => [500, '', []]);
$recipient = $emails->batch([
    'recipients' => [['to' => 'user@example.com', 'distinct_id' => str_repeat("\0", 10 * 1024 * 1024)]],
    'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b',
])->getResults()[0];
echo json_encode([$recipient->getStatus(), $recipient->getError()->getErrorCode(), $recipient->getError()->getField()]);
PHP;
        $this->assertSame(
            [0, '["failed","content_too_large","recipients__0__distinct_id"]'],
            self::runWithMemoryLimit($script, '128M')
        );
    }

    public function testA32MibHtmlIsRefusedWithin128MibOfMemory(): void
    {
        $script = <<<'PHP'
<?php
require $argv[1];
$requests = 0;
$emails = new PostHog\Emails('phs_secret', 'us.i.posthog.com', transport: static function () use (&$requests): array {
    $requests++;

    return [500, '', []];
});
try {
    $emails->batch([
        'recipients' => [['to' => 'user@example.com']],
        'template_key' => 'news',
        'subject' => 'News',
        'html' => str_repeat('x', 32 * 1024 * 1024),
    ]);
} catch (PostHog\EmailError $error) {
    echo json_encode([$requests, $error->getErrorCode(), $error->getField()]);
}
PHP;
        $this->assertSame([0, '[0,"content_too_large","html"]'], self::runWithMemoryLimit($script, '128M'));
    }

    public function testEachRequestOfABatchIsBuiltAfterThePreviousOneIsFreed(): void
    {
        $script = <<<'PHP'
<?php
require $argv[1];
$requests = 0;
$emails = new PostHog\Emails('phs_secret', 'us.i.posthog.com', transport: static function (
    string $method,
    string $url,
    array $headers,
    ?string $body
) use (&$requests): array {
    $requests++;
    if (function_exists('json_validate') && !json_validate($body)) {
        return [400, '{"type":"validation_error","code":"invalid_json","detail":"Bad JSON."}', []];
    }
    preg_match_all('/"idempotency_key"\\s*:\\s*"(key-\\d)"/', $body, $keys);
    $slots = [];
    foreach ($keys[1] as $key) {
        $slots[] = ['message_id' => "msg_$key", 'status' => 'queued'];
    }

    return [200, json_encode(['results' => $slots]), []];
});
$recipients = [];
for ($index = 0; $index < 8; $index++) {
    $recipients[] = [
        'to' => "user$index@example.com",
        'idempotency_key' => "key-$index",
        'template_data' => ['note' => str_repeat(chr(97 + $index), 1010000)],
    ];
}
$result = $emails->batch(['recipients' => $recipients, 'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b']);
$queued = count(array_filter($result->getResults(), static fn($email): bool => $email->getStatus() === 'queued'));
echo json_encode([$requests, $queued]);
PHP;

        $this->assertSame([0, '[2,8]'], self::runWithMemoryLimit($script, '29M'));
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

    public static function requestsAtTheLimit(): array
    {
        return [
            'exactly 4 MiB' => [0, [3]],
            'one byte more' => [1, [2, 1]],
        ];
    }

    #[DataProvider('requestsAtTheLimit')]
    public function testARequestTakesRecipientsUpToExactly4Mib(int $bytesOver, array $recipientsPerRequest): void
    {
        $empty = ['template_id' => self::TEMPLATE_ID, 'message_data' => new stdClass(), 'recipients' => []];
        $base = strlen(json_encode($empty));
        $recipient = ['to' => 'a@example.com', 'idempotency_key' => 'k1', 'message_data' => ['n' => '']];
        $item = strlen(json_encode($recipient));
        $recipient = ['to' => 'a@example.com', 'idempotency_key' => 'k1', 'template_data' => ['n' => '']];
        $note = str_repeat('a', self::FOUR_MIB - $base - 3 * $item - 2 + $bytesOver);
        $this->reply(...array_fill(0, count($recipientsPerRequest), self::allQueued()));

        $this->batch([
            [...$recipient, 'template_data' => ['n' => $note]],
            [...$recipient, 'idempotency_key' => 'k2'],
            [...$recipient, 'idempotency_key' => 'k3'],
        ]);

        $this->assertSame($recipientsPerRequest, $this->recipientsPerRequest());
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

    public function testARetryAfterALostAnswerKeepsARefusedRecipientUnconfirmed(): void
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
        $this->assertSame(['unconfirmed', 'm1'], [$second->getStatus(), $second->getMessageId()]);
        $this->assertSame('delivery_unavailable', $second->getError()->getErrorCode());
        $this->assertSame('service_unavailable', $second->getError()->getErrorName());
        $this->assertSame('idempotency_key_reused', $second->getLaterError()->getErrorCode());
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
        $this->assertSame('sending_paused', $result->getLaterError()->getErrorCode());
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
        $this->assertNull($result->getLaterError());
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
            [['unconfirmed', null, null], ['unconfirmed', null, null]],
            array_map(
                static fn($r): array => [$r->getStatus(), $r->getMessageId(), $r->getLaterError()],
                $error->getResults()
            )
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
                'idempotency_key_in_flight',
                'quota_limited',
            ],
            'an in-flight answer after a 503' => [
                [self::wholeCall(503, 'delivery_unavailable'), self::wholeCall(409, 'idempotency_key_in_flight')],
                'delivery_unavailable',
                'idempotency_key_in_flight',
            ],
        ];
    }

    #[DataProvider('attemptsAfterAnInFlightAnswer')]
    public function testTheAttemptAfterTheOneThatLeftARecipientUnconfirmedIsItsLaterError(
        array $answers,
        string $code,
        string $laterCode
    ): void {
        $this->reply(...$answers);

        $error = $this->batchError(self::recipients(1), ['max_retries' => 1]);

        [$result] = $error->getResults();
        $this->assertSame('unconfirmed', $result->getStatus());
        $this->assertSame($code, $result->getError()->getErrorCode());
        $this->assertSame($laterCode, $result->getLaterError()->getErrorCode());
        $this->assertSame([self::BATCH_URL, self::BATCH_URL], $this->requestUrls());
    }

    public function testAShutdownDuringABatchStopsItBeforeTheNextRequest(): void
    {
        $this->reply(function (string $url, string $body): array {
            $this->closed = true;

            return self::allQueued()($url, $body);
        }, self::allQueued());

        $error = $this->batchError(self::recipients(150), emails: $this->closedEmails());

        $this->assertSame('client_closed', $error->getErrorCode());
        $this->assertCount(1, $this->requests);
        $this->assertSame(
            [...self::times(100, 'queued'), ...self::times(50, 'not_attempted')],
            self::statuses($error->getResults())
        );
        $this->assertSame('client_closed', $error->getResults()[100]->getError()->getErrorCode());
    }

    public function testAShutdownDuringARetryWaitSendsNoRetry(): void
    {
        Clock::set(self::clockThatShutsDownOnSleep($this->clock, function (): void {
            $this->closed = true;
        }));
        $this->reply(self::wholeCall(429), self::allQueued());

        $error = $this->batchError(self::recipients(150), emails: $this->closedEmails());

        $this->assertSame('client_closed', $error->getErrorCode());
        $this->assertCount(1, $this->requests);
        $this->assertSame(
            [...self::times(100, 'failed'), ...self::times(50, 'not_attempted')],
            self::statuses($error->getResults())
        );
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

    public function testARequestRefusedAsAWholeThrowsWithTheResultsSoFar(): void
    {
        $this->reply(self::allQueued(), self::wholeCall(429, 'quota_limited'));

        $error = $this->batchError(self::recipients(250));

        $this->assertInstanceOf(EmailError::class, $error);
        $this->assertSame(
            ['quota_limited', 429, 'Nope.'],
            [$error->getErrorCode(), $error->getStatusCode(), $error->getMessage()]
        );
        $this->assertCount(2, $this->requests);
        $results = $error->getResults();
        $this->assertSame(
            [...self::times(100, 'queued'), ...self::times(100, 'failed'), ...self::times(50, 'not_attempted')],
            self::statuses($results)
        );
        $this->assertSame(['quota_limited', 'Nope.'], [
            $results[150]->getError()->getErrorCode(),
            $results[150]->getError()->getMessage(),
        ]);
        $this->assertSame('not_attempted', $results[200]->getError()->getErrorCode());
        $this->assertSame('key-249', $results[249]->getIdempotencyKey());
    }

    public function testARefusalOfTheWholeRequestAfterALostAnswerKeepsItsRecipientsUnconfirmed(): void
    {
        $this->reply(self::networkError(), self::wholeCall(429, 'quota_limited'));

        $error = $this->batchError(self::recipients(1));

        [$result] = $error->getResults();
        $this->assertSame(['unconfirmed', null], [$result->getStatus(), $result->getMessageId()]);
        $this->assertSame('delivery_unavailable', $result->getError()->getErrorCode());
        $this->assertSame('quota_limited', $result->getLaterError()->getErrorCode());
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

    public function testARetryThatLosesItsAnswerAgainIsTheLaterError(): void
    {
        $this->reply(self::networkError(), self::networkError());

        $error = $this->batchError(self::recipients(1), ['max_retries' => 1]);

        $this->assertSame('network_error', $error->getErrorCode());
        [$result] = $error->getResults();
        $this->assertSame([2, 'unconfirmed'], [count($this->batchBodies()), $result->getStatus()]);
        $this->assertSame('delivery_unavailable', $result->getError()->getErrorCode());
        $this->assertSame('network_error', $result->getLaterError()?->getErrorCode());
    }

    #[DataProvider('retriesAfterAnUnconfirmingAttempt')]
    public function testARetryThatCannotConfirmEitherIsTheLaterError(
        mixed $first,
        mixed $retry,
        string $laterCode
    ): void {
        $this->reply($first, $retry);

        $error = $this->batchError(self::recipients(1), ['max_retries' => 1]);

        [$result] = $error->getResults();
        $this->assertSame('unconfirmed', $result->getStatus());
        $this->assertSame('delivery_unavailable', $result->getError()->getErrorCode());
        $this->assertSame($laterCode, $result->getLaterError()?->getErrorCode());
    }

    public function testAnAnswerWithoutAResultPerRecipientLeavesTheRequestUnconfirmed(): void
    {
        $this->reply(self::ok(self::queued('m0')));

        $error = $this->batchError(self::recipients(2), ['max_retries' => 0]);

        $this->assertSame('api_error', $error->getErrorCode());
        $this->assertSame(['unconfirmed', 'unconfirmed'], self::statuses($error->getResults()));
        $this->assertSame(
            [null, null],
            array_map(static fn($r): ?EmailError => $r->getLaterError(), $error->getResults())
        );
        $this->assertSame([self::BATCH_URL], $this->requestUrls());
    }

    public function testWarningsReachTheCallerOnce(): void
    {
        global $errorMessages;
        $warning = ['code' => 'sandbox_sender', 'detail' => 'Sent from the test sender.'];
        $this->reply(self::allQueued(['warnings' => [$warning]]), self::allQueued(['warnings' => [$warning]]));

        $result = $this->batch(self::recipients(150));

        $codes = array_map(static fn($warning): string => $warning->getCode(), $result->getWarnings());
        $this->assertSame(['sandbox_sender'], $codes);
        $this->assertSame([self::WARNING_LOGGED], $errorMessages);
    }

    public function testWarningsReachTheCallerWhenALaterRequestFails(): void
    {
        global $errorMessages;
        $warning = ['code' => 'sandbox_sender', 'detail' => 'Sent from the test sender.'];
        $this->reply(self::allQueued(['warnings' => [$warning]]), self::wholeCall(429, 'quota_limited'));

        $error = $this->batchError(self::recipients(150));

        $this->assertSame(['sandbox_sender'], array_map(static fn($w): string => $w->getCode(), $error->getWarnings()));
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

    public static function refusedRecipientFields(): array
    {
        return [
            'a position in the request' => ['recipients__37__idempotency_key', 'recipients__137__idempotency_key'],
            'a position alone' => ['recipients__37', 'recipients__137'],
            'not an ASCII number' => ["recipients__\u{00b2}__idempotency_key", "recipients__\u{00b2}__idempotency_key"],
            'past the request' => ['recipients__50__to', 'recipients'],
            'a huge number' => ['recipients__' . str_repeat('9', 5000) . '__idempotency_key', 'recipients'],
            'another field' => ['template_id', 'template_id'],
        ];
    }

    #[DataProvider('refusedRecipientFields')]
    public function testARefusedRecipientFieldNamesItsPositionInRecipients(string $attr, string $field): void
    {
        $this->reply(self::allQueued(), [400, json_encode([
            'type' => 'validation_error',
            'code' => 'max_length',
            'detail' => 'Ensure this field has no more than 255 characters.',
            'attr' => $attr,
        ]), []]);

        $error = $this->batchError(self::recipients(150));

        $this->assertSame($field, $error->getField());
        $this->assertSame(
            [...self::times(100, 'queued'), ...self::times(50, 'failed')],
            self::statuses($error->getResults())
        );
    }
}
