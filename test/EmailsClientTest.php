<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\EmailError;
use PostHog\PostHog;

class EmailsClientTest extends TestCase
{
    private const TEMPLATE_ID = '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b';

    private ?LocalHttpServer $server = null;
    private ?LocalHttpServer $bucket = null;

    protected function setUp(): void
    {
        global $errorMessages;
        $errorMessages = [];
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->bucket?->stop();
    }

    private function client(array $options = [], ?string $apiKey = 'phc_project'): Client
    {
        return new Client(
            $apiKey,
            $options + ['consumer' => 'noop', 'app_host' => 'http://' . $this->server->address()],
            loadFeatureFlags: false,
            secretKey: ' phs_secret '
        );
    }

    public function testACodeSendReachesTheSendRouteOverHttp(): void
    {
        $this->server = new LocalHttpServer([
            ['status' => 202, 'body' => '{"message_id":"01a18356-c56c-0001-e494-7666bc915188","replayed":false}'],
        ]);
        $html = '<p>' . str_repeat('Hello ', 400) . '</p>';

        $result = $this->client(['migrated_from' => 'resend'])->emails()->send([
            'to' => 'user@example.com',
            'template_key' => 'welcome',
            'subject' => 'Welcome',
            'html' => $html,
            'distinct_id' => 'user_123',
            'idempotency_key' => 'welcome:user_123',
        ]);

        $this->assertSame('01a18356-c56c-0001-e494-7666bc915188', $result->getId());
        $requests = $this->server->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST /api/projects/@current/messaging/send/email/ HTTP/1.1', $requests[0]['requestLine']);
        $headers = $requests[0]['headers'];
        $this->assertSame('Bearer phs_secret', $headers['authorization']);
        $this->assertSame('application/json', $headers['content-type']);
        $this->assertSame('application/json', $headers['accept']);
        $this->assertSame('welcome:user_123', $headers['idempotency-key']);
        $this->assertSame('resend', $headers['x-posthog-migrated-from']);
        $this->assertSame('posthog-php/' . PostHog::VERSION, $headers['user-agent']);
        $this->assertArrayNotHasKey('expect', $headers);
        $this->assertSame([
            'template_key' => 'welcome',
            'subject' => 'Welcome',
            'html' => $html,
            'capture_engagement' => true,
            'to' => 'user@example.com',
            'distinct_id' => 'user_123',
        ], json_decode($requests[0]['body'], true));
    }

    public function testAFileIsUploadedWithAPlainPutBeforeTheSendOverHttp(): void
    {
        $file = "%PDF-1.7\n" . str_repeat("0 0 obj << >> endobj\n", 100);
        $sha256 = hash('sha256', $file);
        $checksum = base64_encode(hash('sha256', $file, true));
        $signedPath = "/attachments/team/2/$sha256?X-Amz-Signature=abc";
        $this->bucket = new LocalHttpServer([[
            'status' => 200,
            'body' => '',
            'accepts' => [
                'request_line' => "PUT $signedPath HTTP/1.1",
                'content-length' => (string) strlen($file),
                'x-amz-checksum-sha256' => $checksum,
            ],
            'refusal' => [
                'status' => 403,
                'body' => '<Error><Code>SignatureDoesNotMatch</Code><Message>Not as signed</Message></Error>',
            ],
        ]]);
        $this->server = new LocalHttpServer([
            [
                'status' => 200,
                'body' => json_encode(['files' => [[
                    'sha256' => $sha256,
                    'status' => 'upload',
                    'url' => 'http://' . $this->bucket->address() . $signedPath,
                    'method' => 'PUT',
                    'headers' => ['Content-Length' => (string) strlen($file), 'x-amz-checksum-sha256' => $checksum],
                    'url_expires_at' => '2026-11-02T12:15:00Z',
                ]]]),
                'accepts' => [
                    'request_line' => 'POST /api/projects/@current/messaging/attachments/uploads/ HTTP/1.1',
                    'authorization' => 'Bearer phs_secret',
                    'json_body' => ['files' => [['sha256' => $sha256, 'size' => strlen($file)]]],
                ],
                'refusal' => [
                    'status' => 400,
                    'body' => '{"type":"validation_error","code":"invalid_request","detail":"Not as PostHog signs."}',
                ],
            ],
            ['status' => 202, 'body' => '{"message_id":"01a18356-c56c-0001-e494-7666bc915188"}'],
        ]);

        $result = $this->client()->emails()->send([
            'to' => 'user@example.com',
            'template_key' => 'receipt',
            'subject' => 'Your receipt',
            'text' => 'Thanks!',
            'attachments' => [['filename' => 'receipt.pdf', 'content' => $file]],
        ]);

        $this->assertSame('01a18356-c56c-0001-e494-7666bc915188', $result->getId());
        $this->assertSame([$file], $this->storedFiles());
        $this->assertSame(
            [['sha256' => $sha256, 'filename' => 'receipt.pdf']],
            $this->queuedEmails()[0]['attachments']
        );
    }

    /** @return list<string> the files storage took */
    private function storedFiles(): array
    {
        return array_column(self::accepted($this->bucket, 'PUT '), 'body');
    }

    /** @return list<array<string, mixed>> the emails PostHog queued */
    private function queuedEmails(): array
    {
        return array_map(
            static fn(array $send): array => json_decode($send['body'], true),
            self::accepted($this->server, 'POST /api/projects/@current/messaging/send/email/ ')
        );
    }

    private static function accepted(LocalHttpServer $server, string $requestLinePrefix): array
    {
        return array_values(array_filter(
            $server->acceptedRequests(),
            static fn(array $request): bool => str_starts_with($request['requestLine'], $requestLinePrefix)
        ));
    }

    private static function uploadAnswer(string $storage, string $file): array
    {
        $sha256 = hash('sha256', $file);

        return ['status' => 200, 'body' => json_encode(['files' => [[
            'sha256' => $sha256,
            'status' => 'upload',
            'url' => "http://$storage/attachments/team/2/$sha256?X-Amz-Signature=abc",
            'method' => 'PUT',
            'headers' => ['Content-Length' => (string) strlen($file)],
            'url_expires_at' => '2026-11-02T12:15:00Z',
        ]]])];
    }

    private static function sendWithFile(string $file): array
    {
        return [
            'to' => 'user@example.com',
            'template_key' => 'receipt',
            'subject' => 'Your receipt',
            'text' => 'Thanks!',
            'attachments' => [['filename' => 'receipt.pdf', 'content' => $file]],
        ];
    }

    public function testStorageRefusesBytesThatDoNotMatchTheSignedChecksumAndNothingIsSent(): void
    {
        $file = "%PDF-1.7\n" . str_repeat("0 0 obj << >> endobj\n", 100);
        $this->bucket = new LocalHttpServer([['status' => 200, 'body' => '']]);
        $answer = self::uploadAnswer($this->bucket->address(), $file);
        $slots = json_decode($answer['body'], true);
        $slots['files'][0]['headers']['x-amz-checksum-sha256'] = base64_encode(hash('sha256', 'another file', true));
        $this->server = new LocalHttpServer([['status' => 200, 'body' => json_encode($slots)]]);

        try {
            $this->client(['email_request_max_retries' => 0])->emails()->send(self::sendWithFile($file));
            $this->fail('The send went out after storage refused the file.');
        } catch (EmailError $error) {
            $this->assertSame('upload_failed', $error->getErrorCode());
            $this->assertStringContainsString('BadDigest', $error->getMessage());
        }

        $this->assertSame([], $this->storedFiles());
        $this->assertSame([], $this->queuedEmails(), 'nothing was sent');
    }

    public function testStorageMayTakeLongerThanTheRequestTimeoutToTakeALargerFile(): void
    {
        $file = str_repeat('a', 200_000);
        $this->bucket = new LocalHttpServer([['status' => 200, 'body' => '', 'delay_ms' => 1500]]);
        $this->server = new LocalHttpServer([
            self::uploadAnswer($this->bucket->address(), $file),
            ['status' => 202, 'body' => '{"message_id":"01a18356-c56c-0001-e494-7666bc915188"}'],
        ]);
        $client = $this->client(['email_request_max_retries' => 0, 'email_request_timeout_ms' => 1000]);

        $result = $client->emails()->send(self::sendWithFile($file));

        $this->assertSame('01a18356-c56c-0001-e494-7666bc915188', $result->getId());
        $this->assertSame([$file], $this->storedFiles());
    }

    public function testAnUploadGivesUpConnectingToStorageAtTheRequestTimeout(): void
    {
        $storage = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create(['socket' => ['backlog' => 0]])
        );
        $address = stream_socket_get_name($storage, false);
        $waiting = [];
        for ($i = 0; $i < 4; $i++) {
            $waiting[] = @stream_socket_client("tcp://$address", $errno, $error, 1, STREAM_CLIENT_ASYNC_CONNECT);
        }
        $probeStarted = hrtime(true);
        $probe = @stream_socket_client("tcp://$address", $errno, $error, 0.3);
        if ($probe !== false || (hrtime(true) - $probeStarted) / 1e6 < 250) {
            $this->markTestSkipped('Connecting here does not stall once the listen queue is full.');
        }
        $file = str_repeat('a', 500_000);
        $this->server = new LocalHttpServer([self::uploadAnswer($address, $file)]);
        $client = $this->client(['email_request_max_retries' => 0, 'email_request_timeout_ms' => 1000]);
        $started = hrtime(true);

        try {
            $client->emails()->send(self::sendWithFile($file));
            $this->fail('The upload connected to a storage host that never accepts.');
        } catch (EmailError $error) {
            $this->assertSame('network_error', $error->getErrorCode());
        }

        $this->assertLessThan(3000, (hrtime(true) - $started) / 1e6, 'gave up at the 1 s connect timeout, not 6 s');
        $this->assertSame([], $this->queuedEmails(), 'nothing was sent');
        fclose($storage);
    }

    public function testARetryAfterHeaderComesBackOnTheError(): void
    {
        $this->server = new LocalHttpServer([
            [
                'status' => 429,
                'headers' => ['Retry-After' => '45', 'Content-Type' => 'application/json'],
                'body' => '{"code":"throttled","detail":"Slow down."}',
            ],
        ]);

        try {
            $this->client()->emails()->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);
            $this->fail('send() did not throw');
        } catch (EmailError $error) {
            $this->assertSame('throttled', $error->getErrorCode());
            $this->assertSame('rate_limit_exceeded', $error->getErrorName());
            $this->assertSame(429, $error->getStatusCode());
            $this->assertSame(45000, $error->getRetryAfterMs());
        }
        $this->assertCount(1, $this->server->requests());
    }

    public function testAnOversizedReplyIsNotReadOrRetried(): void
    {
        $this->server = new LocalHttpServer([
            ['status' => 503, 'body' => '{"code":"unavailable"}', 'pad_body_bytes' => 2_000_000],
            ['status' => 200, 'body' => '{"id":"01a18356-c56c-0001-e494-7666bc915188"}', 'pad_body_bytes' => 2_000_000],
        ]);
        $emails = $this->client()->emails();
        $errors = [];

        $calls = [
            fn() => $emails->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]),
            fn() => $emails->get('01a18356-c56c-0001-e494-7666bc915188'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('No EmailError was thrown.');
            } catch (EmailError $error) {
                $errors[] = [$error->getErrorCode(), $error->getErrorName(), $error->getStatusCode()];
            }
        }

        $expected = ['response_too_large', 'application_error', null];
        $this->assertSame([$expected, $expected], $errors);
        $this->assertCount(2, $this->server->requests());
    }

    public function testAClosedPortIsANetworkError(): void
    {
        $this->server = new LocalHttpServer();
        $client = $this->client(['email_request_max_retries' => 0, 'email_request_timeout_ms' => 2000]);
        $this->server->stop();

        try {
            $client->emails()->get('01a18356-c56c-0001-e494-7666bc915188');
            $this->fail('get() did not throw');
        } catch (EmailError $error) {
            $this->assertSame('network_error', $error->getErrorCode());
            $this->assertSame('service_unavailable', $error->getErrorName());
            $this->assertNull($error->getStatusCode());
            $this->assertStringNotContainsString('phs_secret', $error->getMessage());
        }
    }

    public static function badEmailOptions(): array
    {
        return [
            'zero timeout' => [['email_request_timeout_ms' => 0]],
            'timeout not a number' => [['email_request_timeout_ms' => 'soon']],
            'negative retries' => [['email_request_max_retries' => -1]],
            'retries as a word' => [['email_request_max_retries' => 'two']],
            'timeout with a unit' => [['email_request_timeout_ms' => '15seconds']],
            'timeout as a fraction' => [['email_request_timeout_ms' => 1.5]],
            'migrated_from with a space' => [['migrated_from' => 'Resend Inc']],
        ];
    }

    #[DataProvider('badEmailOptions')]
    public function testABadEmailOptionIsRefusedWhenTheClientIsBuilt(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $options += ['consumer' => 'noop'];

        new Client('phc_project', $options, loadFeatureFlags: false, secretKey: 'phs_secret');
    }

    public function testNumericStringEmailOptionsAreRead(): void
    {
        $this->server = new LocalHttpServer([
            ['status' => 503, 'body' => '{"code":"unavailable"}'],
            ['status' => 202, 'body' => '{"message_id":"msg_1"}'],
        ]);

        $error = null;
        try {
            $client = $this->client(['email_request_max_retries' => '0', 'email_request_timeout_ms' => '5000']);
            $client->emails()->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);
        } catch (EmailError $error) {
        }

        $this->assertSame('unavailable', $error?->getErrorCode());
        $this->assertCount(1, $this->server->requests());
    }

    public function testAnEmptyMigratedFromCountsAsLeftOut(): void
    {
        $this->server = new LocalHttpServer([['status' => 202, 'body' => '{"message_id":"msg_1"}']]);

        $this->client(['migrated_from' => ''])->emails()->send([
        'to' => 'user@example.com',
        'template_id' => self::TEMPLATE_ID,
        ]);

        $this->assertArrayNotHasKey('x-posthog-migrated-from', $this->server->requests()[0]['headers']);
    }

    public function testTheClientWithoutAProjectKeyIsDisabledForEmail(): void
    {
        $this->server = new LocalHttpServer();

        try {
            $this->client([], null)->emails()->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);
            $this->fail('send() did not throw');
        } catch (EmailError $error) {
            $this->assertSame('client_disabled', $error->getErrorCode());
        }
        $this->assertSame([], $this->server->requests());
    }

    public function testAShutDownClientSendsNothing(): void
    {
        $this->server = new LocalHttpServer();
        $client = $this->client();
        $emails = $client->emails();
        $client->shutdown();

        try {
            $emails->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);
            $this->fail('send() did not throw');
        } catch (EmailError $error) {
            $this->assertSame('client_closed', $error->getErrorCode());
        }
        $this->assertSame([], $this->server->requests());
    }

    public function testACustomHostWithoutAnAppHostKeepsTheSecretKey(): void
    {
        $this->server = new LocalHttpServer();
        $client = new Client(
            'phc_project',
            ['consumer' => 'noop', 'host' => $this->server->address(), 'ssl' => false],
            loadFeatureFlags: false,
            secretKey: 'phs_secret'
        );

        try {
            $client->emails()->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);
            $this->fail('send() did not throw');
        } catch (EmailError $error) {
            $this->assertSame('missing_app_host', $error->getErrorCode());
        }
        $this->assertSame([], $this->server->requests());
    }

    public function testTheEmailsServiceKeepsItsClientWithoutACycle(): void
    {
        $this->server = new LocalHttpServer([['status' => 202, 'body' => '{"message_id":"msg_1"}']]);
        $client = $this->client();
        $reference = \WeakReference::create($client);
        $emails = $client->emails();
        unset($client);

        $result = $emails->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);
        $this->assertSame('msg_1', $result->getId());
        unset($emails);

        $this->assertNull($reference->get(), 'dropping the service freed the client, so its destructor ran');
    }

    public function testTheFacadeUsesTheDefaultClient(): void
    {
        $this->server = new LocalHttpServer([['status' => 202, 'body' => '{"message_id":"msg_1"}']]);
        PostHog::init(null, null, $this->client());

        $result = PostHog::emails()->send(['to' => 'user@example.com', 'template_id' => self::TEMPLATE_ID]);

        $this->assertSame('msg_1', $result->getId());
        $this->assertCount(1, $this->server->requests());
    }
}
