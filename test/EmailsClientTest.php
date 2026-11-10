<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\EmailError;
use PostHog\Emails;
use PostHog\PostHog;

class EmailsClientTest extends TestCase
{
    private const TEMPLATE_ID = '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b';

    private ?LocalHttpServer $server = null;

    protected function setUp(): void
    {
        global $errorMessages;
        $errorMessages = [];
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
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

    public function testACodeSendDefinesItsCategoryOnTheServer(): void
    {
        $this->server = new LocalHttpServer([], 'email-send-server.php');
        $emails = $this->client()->emails();
        $newsletter = ['key' => 'newsletter', 'type' => 'marketing', 'name' => 'Newsletter'];
        $send = fn(mixed $category): string => $emails->send([
            'to' => 'user@example.com',
            'template_key' => 'monthly-news',
            'subject' => 'This month',
            'html' => '<p>News. {{ unsubscribe_url }}</p>',
            'category' => $category,
        ])->getId();

        $first = $send($newsletter + ['description' => 'Monthly product news']);
        $this->assertSame(
            ['newsletter' => ['type' => 'marketing', 'name' => 'Newsletter', 'description' => 'Monthly product news']],
            $this->server->state()['categories']
        );

        $send(['key' => 'newsletter', 'type' => 'marketing', 'name' => 'Product news', 'description' => null]);
        $this->assertSame(
            ['type' => 'marketing', 'name' => 'Product news', 'description' => 'Monthly product news'],
            $this->server->state()['categories']['newsletter']
        );

        $send($newsletter + ['description' => '']);
        $this->assertSame('', $this->server->state()['categories']['newsletter']['description']);

        $receipt = $send(Emails::CATEGORY_TRANSACTIONAL);
        $this->assertSame(
            [
                ['message_id' => $first, 'category' => 'newsletter', 'type' => 'marketing'],
                ['message_id' => $receipt, 'category' => null, 'type' => 'transactional'],
            ],
            [$this->server->state()['sends'][0], $this->server->state()['sends'][3]]
        );

        try {
            $send(['key' => 'newsletter', 'type' => Emails::CATEGORY_TRANSACTIONAL, 'name' => 'Newsletter']);
            $this->fail('send() did not throw');
        } catch (EmailError $error) {
            $this->assertSame('category_mismatch', $error->getErrorCode());
            $this->assertSame('validation_error', $error->getErrorName());
            $this->assertSame(400, $error->getStatusCode());
            $this->assertSame('category', $error->getField());
        }
        $this->assertSame('marketing', $this->server->state()['categories']['newsletter']['type']);
        $this->assertCount(4, $this->server->state()['sends']);
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
