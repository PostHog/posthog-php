<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\PostHog;
use PostHog\Test\Assets\MockedResponses;
use RuntimeException;

class ReleaseIdTest extends TestCase
{
    private const FAKE_API_KEY = "random_key";
    private const ENV_RELEASE_ID = "POSTHOG_RELEASE_ID";

    private string|false $previousReleaseId;
    private MockedHttpClient $httpClient;

    public function setUp(): void
    {
        date_default_timezone_set("UTC");
        $this->previousReleaseId = getenv(self::ENV_RELEASE_ID);
    }

    public function tearDown(): void
    {
        $this->setReleaseIdEnv($this->previousReleaseId === false ? null : $this->previousReleaseId);
    }

    public static function eventCases(): array
    {
        return [
            'capture' => ['Module PHP Event', static function (Client $client): void {
                $client->capture(["distinctId" => "john", "event" => "Module PHP Event"]);
            }],
            'captureException' => ['$exception', static function (Client $client): void {
                $client->captureException(new RuntimeException("boom"), "john");
            }],
            'identify' => ['$identify', static function (Client $client): void {
                $client->identify(["distinctId" => "john", "properties" => ["email" => "john@example.com"]]);
            }],
            'alias' => ['$create_alias', static function (Client $client): void {
                $client->alias(["distinctId" => "john", "alias" => "anonymous-id"]);
            }],
            'groupIdentify' => ['$groupidentify', static function (Client $client): void {
                PostHog::init(null, null, $client);
                PostHog::groupIdentify(["groupType" => "company", "groupKey" => "id:5"]);
            }],
            'full $feature_flag_called' => ['$feature_flag_called', static function (Client $client): void {
                $client->evaluateFlags('john')->isEnabled('simple-test');
            }],
            'minimal $feature_flag_called' => ['$feature_flag_called', static function (Client $client): void {
                $client->evaluateFlags('john')->isEnabled('simple-test');
            }, true],
        ];
    }

    #[DataProvider('eventCases')]
    public function testReleaseIdIsSentOnEveryEvent(
        string $expectedEvent,
        callable $send,
        bool $minimalFlagCalledEvents = false
    ): void {
        $this->setReleaseIdEnv("rel-123");
        $flagsResponse = MockedResponses::FLAGS_V2_RESPONSE;
        if ($minimalFlagCalledEvents) {
            $flagsResponse['minimalFlagCalledEvents'] = true;
            $flagsResponse['flags']['simple-test']['metadata']['has_experiment'] = false;
        }
        $client = $this->createClient($flagsResponse);

        $send($client);
        $client->flush();

        $event = $this->onlyBatchEvent();
        $this->assertSame($expectedEvent, $event['event']);
        $this->assertSame("rel-123", $event['properties']['$release_id']);
        if ($minimalFlagCalledEvents) {
            // Proves the allowlist ran, so the release id survived minimization rather than skipping it.
            $this->assertArrayNotHasKey('$lib_consumer', $event['properties']);
        }
    }

    public static function envValueCases(): array
    {
        return [
            'set' => ["rel-123", "rel-123"],
            'surrounding whitespace is trimmed' => ["  rel-123 \n", "rel-123"],
            'empty counts as unset' => ["", null],
            'whitespace only counts as unset' => [" \t ", null],
            'unset' => [null, null],
        ];
    }

    #[DataProvider('envValueCases')]
    public function testReleaseIdFollowsEnvValue(?string $envValue, ?string $expected): void
    {
        $this->setReleaseIdEnv($envValue);
        $client = $this->createClient();

        $client->capture(["distinctId" => "john", "event" => "Module PHP Event"]);
        $client->flush();

        $properties = $this->onlyBatchEvent()['properties'];
        if ($expected === null) {
            $this->assertArrayNotHasKey('$release_id', $properties);
        } else {
            $this->assertSame($expected, $properties['$release_id']);
        }
    }

    public static function explicitReleaseIdCases(): array
    {
        return [
            'event property' => [static function (Client $client): void {
                $client->capture([
                    "distinctId" => "john",
                    "event" => "Module PHP Event",
                    "properties" => ['$release_id' => "explicit"],
                ]);
            }],
            'request context property' => [static function (Client $client): void {
                $client->withContext(
                    ["properties" => ['$release_id' => "explicit"]],
                    static function () use ($client): void {
                        $client->capture(["distinctId" => "john", "event" => "Module PHP Event"]);
                    }
                );
            }],
        ];
    }

    #[DataProvider('explicitReleaseIdCases')]
    public function testExplicitReleaseIdWinsOverEnv(callable $send): void
    {
        $this->setReleaseIdEnv("rel-123");
        $client = $this->createClient();

        $send($client);
        $client->flush();

        $this->assertSame("explicit", $this->onlyBatchEvent()['properties']['$release_id']);
    }

    private function createClient(array $flagsResponse = MockedResponses::FLAGS_V2_RESPONSE): Client
    {
        $this->httpClient = new MockedHttpClient("app.posthog.com", flagsEndpointResponse: $flagsResponse);

        return new Client(self::FAKE_API_KEY, [], $this->httpClient, null, false);
    }

    private function onlyBatchEvent(): array
    {
        $events = [];
        foreach ($this->httpClient->calls as $call) {
            if (($call["path"] ?? null) === "/batch/") {
                $events = array_merge($events, json_decode($call["payload"], true)["batch"]);
            }
        }
        $this->assertCount(1, $events);

        return $events[0];
    }

    private function setReleaseIdEnv(?string $value): void
    {
        putenv($value === null ? self::ENV_RELEASE_ID : self::ENV_RELEASE_ID . "=" . $value);
    }
}
