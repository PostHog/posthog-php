<?php

// phpcs:disable PSR1.Files.SideEffects
namespace PostHog\Test;

// comment out below to print all logs instead of failing tests
require_once 'test/error_log_mock.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\PostHog;
use PostHog\Test\Assets\MockedResponses;

class OptOutTest extends TestCase
{
    private const FAKE_API_KEY = 'random_key';

    private MockedHttpClient $http_client;
    private Client $client;

    public function setUp(): void
    {
        date_default_timezone_set('UTC');
        global $errorMessages;
        $errorMessages = [];

        $this->makeClient();
    }

    private function makeClient(?string $apiKey = self::FAKE_API_KEY): void
    {
        $this->http_client = new MockedHttpClient(
            'app.posthog.com',
            flagsEndpointResponse: MockedResponses::FLAGS_V2_RESPONSE
        );
        $this->client = new Client($apiKey, [], $this->http_client);
        PostHog::init(null, null, $this->client);
    }

    private function batchRequests(): array
    {
        $batches = [];
        foreach ($this->http_client->calls ?? [] as $call) {
            if (str_starts_with($call['path'], '/batch/')) {
                $batches[] = json_decode($call['payload'], true);
            }
        }

        return $batches;
    }

    private function deliveredEvents(): array
    {
        $events = [];
        foreach ($this->batchRequests() as $batch) {
            foreach ($batch['batch'] as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    public static function captureCalls(): array
    {
        return [
            'capture' => [fn (Client $client) => $client->capture(['event' => 'ignored', 'distinctId' => 'john'])],
            'captureException' => [fn (Client $client) => $client->captureException(new \Exception('nope'), 'john')],
            'identify' => [fn (Client $client) => $client->identify(['distinctId' => 'john'])],
            'groupIdentify' => [
                fn (Client $client) => $client->groupIdentify(['groupType' => 'company', 'groupKey' => 'acme']),
            ],
            'alias' => [fn (Client $client) => $client->alias(['distinctId' => 'john', 'alias' => 'johnny'])],
            'raw' => [fn (Client $client) => $client->raw(['event' => 'ignored', 'distinct_id' => 'john'])],
        ];
    }

    public function testClientStartsOptedIn(): void
    {
        self::assertFalse($this->client->isOptedOut());
        self::assertTrue($this->client->capture(['event' => 'allowed', 'distinctId' => 'john']));
    }

    #[DataProvider('captureCalls')]
    public function testOptedOutCallsAreDroppedSilently(callable $call): void
    {
        $this->client->optOutCapturing();

        self::assertTrue($this->client->isOptedOut());
        self::assertFalse($call($this->client));

        $this->client->flush();
        self::assertSame([], $this->http_client->calls ?? []);
    }

    public function testOptInResumesCapturing(): void
    {
        $this->client->optOutCapturing();
        $this->client->capture(['event' => 'dropped', 'distinctId' => 'john']);
        $this->client->optInCapturing();

        self::assertFalse($this->client->isOptedOut());
        self::assertTrue($this->client->capture(['event' => 'resumed', 'distinctId' => 'john']));

        $this->client->flush();
        $events = $this->deliveredEvents();
        self::assertCount(1, $events);
        self::assertSame('resumed', $events[0]['event']);
    }

    public function testEventsAcceptedBeforeOptOutStayDeliverable(): void
    {
        self::assertTrue($this->client->capture(['event' => 'accepted', 'distinctId' => 'john']));
        $this->client->optOutCapturing();
        $this->client->flush();

        $events = $this->deliveredEvents();
        self::assertCount(1, $events);
        self::assertSame('accepted', $events[0]['event']);
    }

    public function testFlagEvaluationStillWorksButSendsNoFlagCalledEvent(): void
    {
        $this->client->optOutCapturing();

        $snapshot = $this->client->evaluateFlags('user-1');
        self::assertTrue($snapshot->isEnabled('simple-test'));

        $this->client->flush();
        self::assertSame([], $this->deliveredEvents());
    }

    public function testFlagCalledIsReportedAfterOptingBackIn(): void
    {
        $this->client->optOutCapturing();
        $this->client->evaluateFlags('user-1')->isEnabled('simple-test');
        $this->client->optInCapturing();
        $this->client->evaluateFlags('user-1')->isEnabled('simple-test');
        $this->client->flush();

        $events = $this->deliveredEvents();
        self::assertCount(1, $events);
        self::assertSame('$feature_flag_called', $events[0]['event']);
    }

    public function testClientWithoutApiKeyIsAlwaysOptedOut(): void
    {
        $this->makeClient(apiKey: null);

        self::assertTrue($this->client->isOptedOut());

        $this->client->optInCapturing();
        self::assertTrue($this->client->isOptedOut());
    }

    public function testFacadeTogglesTheDefaultClient(): void
    {
        self::assertFalse(PostHog::isOptedOut());

        PostHog::optOutCapturing();
        self::assertTrue(PostHog::isOptedOut());
        self::assertFalse(PostHog::capture(['event' => 'ignored', 'distinctId' => 'john']));

        PostHog::optInCapturing();
        self::assertFalse(PostHog::isOptedOut());
        self::assertTrue(PostHog::capture(['event' => 'allowed', 'distinctId' => 'john']));

        PostHog::flush();
        $events = $this->deliveredEvents();
        self::assertCount(1, $events);
        self::assertSame('allowed', $events[0]['event']);
    }
}
