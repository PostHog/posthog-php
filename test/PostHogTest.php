<?php

namespace PostHog\Test;

// comment out below to print all logs instead of failing tests
require_once 'test/error_log_mock.php';

use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use PostHog\Client;
use PostHog\Consumer\NoOp;
use PostHog\PostHog;
use PostHog\Test\Assets\MockedResponses;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;


class PostHogTest extends TestCase
{
    use ClockMockTrait;

    const FAKE_API_KEY = "random_key";

    private $http_client;
    private $client;

    public function setUp(): void
    {
        date_default_timezone_set("UTC");
        $this->http_client = new MockedHttpClient("app.posthog.com");
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        // Reset the errorMessages array before each test
        global $errorMessages;
        $errorMessages = [];
    }

    public function checkEmptyErrorLogs(): void
    {
        global $errorMessages;
        $this->assertEmpty($errorMessages);
    }

    private function getConsumer(Client $client): object
    {
        $ref = new \ReflectionClass($client);
        $consumerProp = $ref->getProperty('consumer');

        return $consumerProp->getValue($client);
    }

    private function firstBatchEvent(): array
    {
        foreach ($this->http_client->calls as $call) {
            if (($call["path"] ?? null) === "/batch/") {
                $decoded = json_decode($call["payload"], true);
                return $decoded["batch"][0];
            }
        }

        self::fail("Expected a /batch/ call to have been made");
    }

    private function assertValidUuidV4(string $uuid): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    private function assertAndStripBatchUuid(int $callIndex): void
    {
        $payload = json_decode($this->http_client->calls[$callIndex]['payload'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('batch', $payload);

        foreach ($payload['batch'] as $index => $event) {
            $this->assertArrayHasKey('uuid', $event);
            $this->assertValidUuidV4($event['uuid']);
            unset($payload['batch'][$index]['uuid']);
        }

        $this->http_client->calls[$callIndex]['payload'] = json_encode($payload);
    }

    private function withEnvApiKey(?string $apiKey, callable $callback): void
    {
        $previousApiKey = getenv(PostHog::ENV_API_KEY);

        if ($apiKey === null) {
            putenv(PostHog::ENV_API_KEY);
        } else {
            putenv(PostHog::ENV_API_KEY . "=" . $apiKey);
        }

        try {
            $callback();
        } finally {
            if ($previousApiKey === false) {
                putenv(PostHog::ENV_API_KEY);
            } else {
                putenv(PostHog::ENV_API_KEY . "=" . $previousApiKey);
            }
        }
    }

    private function unsetFacadeClient(): void
    {
        $resetClient = \Closure::bind(function (): void {
            self::$client = null;
        }, null, PostHog::class);

        $resetClient();
    }

    public static function initNoOpApiKeyCases(): array
    {
        return [
            'empty api key' => [""],
            'whitespace api key' => [" \n\t "],
        ];
    }

    public static function captureGroupsCases(): array
    {
        return [
            'groups key' => ["groups"],
            '$groups key' => ['$groups'],
        ];
    }

    public static function disabledClientNoRequestCases(): array
    {
        return [
            'null api key with getAllFlags' => [null, 'getAllFlags'],
            'whitespace api key with fetchFeatureVariants' => [" \n\t ", 'fetchFeatureVariants'],
        ];
    }

    public static function queuedBatchSizeCases(): array
    {
        return [
            'default' => [["debug" => true]],
            'zero' => [["debug" => true, "batch_size" => 0]],
            'negative' => [["debug" => true, "batch_size" => -1]],
        ];
    }

    public static function validTopLevelUuidCases(): array
    {
        return [
            'v1 UUID' => ['01890f87-d7e7-1c75-8d35-8a1e16b6b0bf'],
            'v2 UUID' => ['01890f87-d7e7-2c75-8d35-8a1e16b6b0bf'],
            'v3 UUID' => ['01890f87-d7e7-3c75-8d35-8a1e16b6b0bf'],
            'v4 UUID' => ['01890f87-d7e7-4c75-8d35-8a1e16b6b0bf'],
            'v5 UUID' => ['01890f87-d7e7-5c75-8d35-8a1e16b6b0bf'],
            'v6 UUID' => ['01890f87-d7e7-6c75-8d35-8a1e16b6b0bf'],
            'v7 UUID' => ['01890f87-d7e7-7c75-8d35-8a1e16b6b0bf'],
            'v8 UUID' => ['01890f87-d7e7-8c75-8d35-8a1e16b6b0bf'],
        ];
    }

    public static function invalidTopLevelUuidCases(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'zero' => [0],
            'false' => [false],
            'non-UUID string' => ['not-a-uuid'],
            'nil UUID' => ['00000000-0000-0000-0000-000000000000'],
        ];
    }

    public static function facadeNoOpBeforeInitCases(): array
    {
        return [
            'capture' => [
                static fn() => PostHog::capture([
                    "distinctId" => "john",
                    "event" => "Module PHP Event",
                ]),
                false,
            ],
            'identify' => [
                static fn() => PostHog::identify(["distinctId" => "john"]),
                false,
            ],
            'alias' => [
                static fn() => PostHog::alias([
                    "distinctId" => "john",
                    "alias" => "anonymous",
                ]),
                false,
            ],
            'groupIdentify' => [
                static fn() => PostHog::groupIdentify([
                    "groupType" => "organization",
                    "groupKey" => "id:5",
                ]),
                false,
            ],
            'raw' => [
                static fn() => PostHog::raw(["type" => "capture"]),
                false,
            ],
            'flush' => [
                static fn() => PostHog::flush(),
                false,
            ],
            'getFeatureFlagPayload' => [
                static fn() => PostHog::getFeatureFlagPayload("flag", "john"),
                null,
            ],
            'fetchFeatureVariants' => [
                static fn() => PostHog::fetchFeatureVariants("john"),
                [],
            ],
        ];
    }

    public function testInitWithParamApiKey(): void
    {
        $this->expectNotToPerformAssertions();

        PostHog::init("BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg", array("debug" => true));
    }

    public function testInitWithEnvApiKey(): void
    {
        $this->expectNotToPerformAssertions();

        $this->withEnvApiKey("BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg", function () {
            PostHog::init(null, array("debug" => true));
        });
    }

    public function testClientTrimsWhitespaceSensitiveConfig(): void
    {
        $client = new Client(
            " \nrandom_key\t ",
            ["host" => " \nhttps://app.posthog.com/\t ", "debug" => true],
            $this->http_client,
            " \n\t "
        );

        $ref = new \ReflectionClass($client);
        $apiKeyProp = $ref->getProperty('apiKey');
        $apiKeyProp->setAccessible(true);
        $personalApiKeyProp = $ref->getProperty('personalAPIKey');
        $personalApiKeyProp->setAccessible(true);
        $optionsProp = $ref->getProperty('options');
        $optionsProp->setAccessible(true);

        $this->assertEquals('random_key', $apiKeyProp->getValue($client));
        $this->assertNull($personalApiKeyProp->getValue($client));
        $this->assertEquals('https://app.posthog.com/', $optionsProp->getValue($client)['host']);
    }

    public function testClientLogsWhenApiKeyIsEmptyAfterTrimmingWhitespace(): void
    {
        new Client(" \n\t ", ["debug" => true], $this->http_client);

        global $errorMessages;
        $this->assertContains(
            '[PostHog][Client] apiKey is empty after trimming whitespace; check your project API key',
            $errorMessages
        );
    }

    public function testInitWithHttpHostSetsSslFalse(): void
    {
        PostHog::init("random_key", ["host" => "http://localhost:8010"]);

        $client = PostHog::getClient();
        $ref = new \ReflectionClass($client);
        $consumerProp = $ref->getProperty('consumer');
        $consumerProp->setAccessible(true);
        $consumer = $consumerProp->getValue($client);

        $cRef = new \ReflectionClass($consumer);
        $httpProp = $cRef->getProperty('httpClient');
        $httpProp->setAccessible(true);
        $httpClient = $httpProp->getValue($consumer);

        $hRef = new \ReflectionClass($httpClient);
        $sslProp = $hRef->getProperty('useSsl');
        $sslProp->setAccessible(true);

        $this->assertFalse($sslProp->getValue($httpClient), 'HttpClient should use ssl=false for http:// hosts');
    }

    public function testInitDefaultsBlankHostAfterTrimmingWhitespace(): void
    {
        PostHog::init("random_key", ["host" => " \n\t "]);

        $client = PostHog::getClient();
        $ref = new \ReflectionClass($client);
        $consumerProp = $ref->getProperty('consumer');
        $consumerProp->setAccessible(true);
        $consumer = $consumerProp->getValue($client);

        $cRef = new \ReflectionClass($consumer);
        $hostProp = $cRef->getProperty('host');
        $hostProp->setAccessible(true);

        $this->assertEquals('us.i.posthog.com', $hostProp->getValue($consumer));
    }

    public function testInitWithWhitespacePaddedHttpHostSetsSslFalse(): void
    {
        PostHog::init("random_key", ["host" => " \nhttp://localhost:8010\t "]);

        $client = PostHog::getClient();
        $ref = new \ReflectionClass($client);
        $consumerProp = $ref->getProperty('consumer');
        $consumerProp->setAccessible(true);
        $consumer = $consumerProp->getValue($client);

        $cRef = new \ReflectionClass($consumer);
        $httpProp = $cRef->getProperty('httpClient');
        $httpProp->setAccessible(true);
        $httpClient = $httpProp->getValue($consumer);

        $hRef = new \ReflectionClass($httpClient);
        $sslProp = $hRef->getProperty('useSsl');
        $sslProp->setAccessible(true);

        $this->assertFalse($sslProp->getValue($httpClient), 'HttpClient should use ssl=false for whitespace-padded http:// hosts');
    }

    public function testInitWithHttpsHostSetsSslTrue(): void
    {
        PostHog::init("random_key", ["host" => "https://app.posthog.com"]);

        $client = PostHog::getClient();
        $ref = new \ReflectionClass($client);
        $consumerProp = $ref->getProperty('consumer');
        $consumerProp->setAccessible(true);
        $consumer = $consumerProp->getValue($client);

        $cRef = new \ReflectionClass($consumer);
        $httpProp = $cRef->getProperty('httpClient');
        $httpProp->setAccessible(true);
        $httpClient = $httpProp->getValue($consumer);

        $hRef = new \ReflectionClass($httpClient);
        $sslProp = $hRef->getProperty('useSsl');
        $sslProp->setAccessible(true);

        $this->assertTrue($sslProp->getValue($httpClient), 'HttpClient should use ssl=true for https:// hosts');
    }

    public function testInitWithEnvHttpHostSetsSslFalse(): void
    {
        putenv(PostHog::ENV_HOST . "=http://localhost:8010");
        PostHog::init("random_key");

        $client = PostHog::getClient();
        $ref = new \ReflectionClass($client);
        $consumerProp = $ref->getProperty('consumer');
        $consumerProp->setAccessible(true);
        $consumer = $consumerProp->getValue($client);

        $cRef = new \ReflectionClass($consumer);
        $httpProp = $cRef->getProperty('httpClient');
        $httpProp->setAccessible(true);
        $httpClient = $httpProp->getValue($consumer);

        $hRef = new \ReflectionClass($httpClient);
        $sslProp = $hRef->getProperty('useSsl');
        $sslProp->setAccessible(true);

        $this->assertFalse($sslProp->getValue($httpClient), 'HttpClient should use ssl=false for http:// env host');

        putenv(PostHog::ENV_HOST);
    }

    public function testInitWithoutApiKeyConfiguresNoOpClient(): void
    {
        $this->withEnvApiKey(null, function () {
            PostHog::init();
            $client = PostHog::getClient();

            $this->assertInstanceOf(NoOp::class, $this->getConsumer($client));
            $this->assertFalse(PostHog::capture([
                "distinctId" => "john",
                "event" => "Module PHP Event",
            ]));
        });
    }

    /**
     * @dataProvider initNoOpApiKeyCases
     */
    public function testInitWithBlankApiKeyConfiguresNoOpClient(string $apiKey): void
    {
        $this->withEnvApiKey(null, function () use ($apiKey) {
            PostHog::init($apiKey, ["debug" => true]);
            $client = PostHog::getClient();

            $this->assertInstanceOf(NoOp::class, $this->getConsumer($client));
            $this->assertFalse(PostHog::capture([
                "distinctId" => "john",
                "event" => "Module PHP Event",
            ]));
        });
    }

    public function testInitWithEmptyApiKeyFallsBackToEnvApiKey(): void
    {
        $this->withEnvApiKey(self::FAKE_API_KEY, function () {
            PostHog::init("", ["debug" => true]);
            $client = PostHog::getClient();

            $ref = new \ReflectionClass($client);
            $apiKeyProp = $ref->getProperty('apiKey');
            $apiKeyProp->setAccessible(true);

            $this->assertSame(self::FAKE_API_KEY, $apiKeyProp->getValue($client));
            $this->assertNotInstanceOf(NoOp::class, $this->getConsumer($client));
        });
    }

    /**
     * @dataProvider disabledClientNoRequestCases
     */
    public function testClientWithBlankApiKeyDoesNotSendRequests(?string $apiKey, string $flagsMethod): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $client = new Client($apiKey, ["debug" => true, "batch_size" => 1], $httpClient);

        $this->assertInstanceOf(NoOp::class, $this->getConsumer($client));
        $this->assertFalse($client->capture([
            "distinctId" => "john",
            "event" => "Module PHP Event",
        ]));
        $this->assertSame([], $client->{$flagsMethod}("john"));
        $this->assertSame([], $httpClient->calls ?? []);
    }

    /**
     * @dataProvider queuedBatchSizeCases
     */
    public function testCapturesStayQueuedUntilFlush(array $options): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $client = new Client(self::FAKE_API_KEY, $options, $httpClient, null, false);

        $this->assertTrue($client->capture([
            "distinctId" => "john",
            "event" => "Module PHP Event",
        ]));
        $this->assertSame(0, count($httpClient->calls ?? []));

        $this->assertTrue($client->flush());
        $this->assertSame(1, count($httpClient->calls ?? []));
        $this->assertSame('/batch/', $httpClient->calls[0]['path']);
    }

    public function testBatchSizeOneFlushesImmediately(): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $client = new Client(
            self::FAKE_API_KEY,
            ["debug" => true, "batch_size" => 1],
            $httpClient,
            null,
            false
        );

        $this->assertTrue($client->capture([
            "distinctId" => "john",
            "event" => "Module PHP Event",
        ]));
        $this->assertSame(1, count($httpClient->calls ?? []));
        $this->assertSame('/batch/', $httpClient->calls[0]['path']);
    }

    public function testBeforeSendCanModifyFullyEnrichedEvent(): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $sawFullyEnrichedEvent = false;
        $client = new Client(
            self::FAKE_API_KEY,
            [
                "batch_size" => 1,
                "before_send" => function (array $event) use (&$sawFullyEnrichedEvent): array {
                    $sawFullyEnrichedEvent = isset(
                        $event['properties']['$lib'],
                        $event['properties']['$lib_version'],
                        $event['properties']['$lib_consumer'],
                        $event['properties']['$is_server']
                    );
                    unset($event['properties']['secret']);
                    $event['properties']['before_send'] = true;

                    return $event;
                },
            ],
            $httpClient,
            null,
            false
        );

        $this->assertTrue($client->capture([
            "distinctId" => "john",
            "event" => "Module PHP Event",
            "properties" => ["secret" => "remove"],
        ]));

        $this->assertTrue($sawFullyEnrichedEvent);
        $payload = json_decode($httpClient->calls[0]['payload'], true);
        $properties = $payload['batch'][0]['properties'];
        $this->assertTrue($properties['before_send']);
        $this->assertArrayNotHasKey('secret', $properties);
    }

    public function testBeforeSendCanDropEvent(): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $client = new Client(
            self::FAKE_API_KEY,
            ["batch_size" => 1, "before_send" => static fn(array $event): ?array => null],
            $httpClient,
            null,
            false
        );

        $this->assertFalse($client->capture([
            "distinctId" => "john",
            "event" => "Module PHP Event",
        ]));
        $this->assertSame([], $httpClient->calls ?? []);
    }

    public function testBeforeSendRunsForIdentify(): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $client = new Client(
            self::FAKE_API_KEY,
            [
                "batch_size" => 1,
                "before_send" => static function (array $event): array {
                    $event['properties']['before_send'] = true;
                    unset($event['properties']['secret']);
                    return $event;
                },
            ],
            $httpClient,
            null,
            false
        );

        $this->assertTrue($client->identify([
            "distinctId" => "john",
            "properties" => ["secret" => "remove"],
        ]));

        $payload = json_decode($httpClient->calls[0]['payload'], true);
        $event = $payload['batch'][0];
        $this->assertSame('$identify', $event['event']);
        $this->assertTrue($event['properties']['before_send']);
        $this->assertArrayNotHasKey('secret', $event['properties']);
    }

    public function testBeforeSendRunsForAlias(): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $client = new Client(
            self::FAKE_API_KEY,
            [
                "batch_size" => 1,
                "before_send" => static function (array $event): array {
                    $event['properties']['before_send'] = true;
                    unset($event['properties']['secret']);
                    return $event;
                },
            ],
            $httpClient,
            null,
            false
        );

        $this->assertTrue($client->alias([
            "distinctId" => "john",
            "alias" => "anonymous-id",
            "properties" => ["secret" => "remove"],
        ]));

        $payload = json_decode($httpClient->calls[0]['payload'], true);
        $event = $payload['batch'][0];
        $this->assertSame('$create_alias', $event['event']);
        $this->assertTrue($event['properties']['before_send']);
        $this->assertArrayNotHasKey('secret', $event['properties']);
    }

    public function testBeforeSendThrowingCallbackDropsEvent(): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $client = new Client(
            self::FAKE_API_KEY,
            [
                "batch_size" => 1,
                "before_send" => static fn(array $event): array => throw new RuntimeException('before_send failed'),
            ],
            $httpClient,
            null,
            false
        );

        $this->assertFalse($client->capture([
            "distinctId" => "john",
            "event" => "Module PHP Event",
        ]));
        $this->assertSame([], $httpClient->calls ?? []);
    }

    /**
     * @dataProvider invalidBeforeSendCases
     */
    public function testBeforeSendInvalidCallbackPathsCaptureOriginalEvent(mixed $beforeSend): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $client = new Client(
            self::FAKE_API_KEY,
            ["batch_size" => 1, "before_send" => $beforeSend],
            $httpClient,
            null,
            false
        );

        $this->assertTrue($client->capture([
            "distinctId" => "john",
            "event" => "Module PHP Event",
            "properties" => ["original" => true],
        ]));

        $payload = json_decode($httpClient->calls[0]['payload'], true);
        $this->assertTrue($payload['batch'][0]['properties']['original']);
    }

    public static function invalidBeforeSendCases(): iterable
    {
        yield 'returns non-array' => [static fn(array $event): string => 'invalid'];
        yield 'not callable' => ['not-callable'];
    }


    /**
     * @dataProvider facadeNoOpBeforeInitCases
     */
    public function testFacadeMethodsNoOpBeforeInit(callable $call, mixed $expectedValue): void
    {
        $this->unsetFacadeClient();

        try {
            $this->assertSame($expectedValue, $call());
            $this->assertInstanceOf(NoOp::class, $this->getConsumer(PostHog::getClient()));

            global $errorMessages;
            $this->assertContains(
                '[PostHog] PostHog::init() was not called; SDK will no-op.',
                $errorMessages
            );
            $this->assertNotContains(
                '[PostHog][Client] apiKey is empty after trimming whitespace; check your project API key',
                $errorMessages
            );
        } finally {
            PostHog::init(null, null, $this->client);
        }
    }

    public function testDirectFlagsApisReturnDefaultsOnApiError(): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com", flagsEndpointResponseCode: 401);
        $client = new Client(self::FAKE_API_KEY, ["debug" => true], $httpClient, null, false);

        $this->assertSame([
            'featureFlags' => [],
            'featureFlagPayloads' => [],
            'flags' => [],
        ], $client->flags("john"));
        $this->assertSame([], $client->fetchFeatureVariants("john"));
    }

    public function testDisabledClientLoadFlagsDoesNotMutateCachedFlags(): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $client = new Client("", ["debug" => true], $httpClient, "test", false);
        $client->featureFlags = [["key" => "existing-flag"]];
        $client->groupTypeMapping = ["0" => "organization"];
        $client->cohorts = ["1" => ["type" => "static"]];

        $client->loadFlags();

        $this->assertSame([["key" => "existing-flag"]], $client->featureFlags);
        $this->assertSame(["0" => "organization"], $client->groupTypeMapping);
        $this->assertSame(["1" => ["type" => "static"]], $client->cohorts);
        $this->assertSame([], $httpClient->calls ?? []);
    }

    public function testClientWithTrimEmptyApiKeyReturnsDefaultFlagEvaluations(): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $client = new Client(" \n\t ", ["debug" => true], $httpClient);

        $flags = $client->evaluateFlags("john");

        $this->assertSame([], $flags->getKeys());
        $this->assertFalse($flags->isEnabled("missing-flag"));
        $this->assertNull($flags->getFlag("missing-flag"));
        $this->assertNull($flags->getFlagPayload("missing-flag"));
        $this->assertSame([], $httpClient->calls ?? []);
    }

    public function testCapture(): void
    {
        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "john",
                    "event" => "Module PHP Event",
                )
            )
        );
    }

    /**
     * @dataProvider captureGroupsCases
     */
    public function testCaptureAddsGroupsProperty(string $groupsKey): void
    {
        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "john",
                    "event" => "grouped event",
                    $groupsKey => array("team" => 1),
                )
            )
        );
        PostHog::flush();

        $event = $this->firstBatchEvent();

        self::assertSame(array("team" => 1), $event["properties"]['$groups']);
    }

    public function testCaptureFlushesOnNextEnqueueAfterDefaultFlushInterval(): void
    {
        $mockClock = new MockClock(new \DateTimeImmutable('2022-05-01 00:00:00'));
        Clock::set($mockClock);

        try {
            $httpClient = new MockedHttpClient("app.posthog.com");
            $client = new Client(
                self::FAKE_API_KEY,
                [
                    "debug" => true,
                    "batch_size" => 100,
                ],
                $httpClient,
                null,
                false
            );

            $this->assertTrue($client->capture(["distinctId" => "john", "event" => "one"]));
            $this->assertSame([], $httpClient->calls ?? []);

            $mockClock->sleep(4);
            $this->assertTrue($client->capture(["distinctId" => "john", "event" => "two"]));
            $this->assertSame([], $httpClient->calls ?? []);

            $mockClock->sleep(1);
            $this->assertTrue($client->capture(["distinctId" => "john", "event" => "three"]));

            $batchCalls = array_values(array_filter(
                $httpClient->calls ?? [],
                static fn(array $call): bool => ($call["path"] ?? null) === "/batch/"
            ));
            $this->assertCount(1, $batchCalls);

            $payload = json_decode($batchCalls[0]["payload"], true);
            $this->assertSame(["one", "two", "three"], array_column($payload["batch"], "event"));
        } finally {
            Clock::set(new NativeClock());
        }
    }

    public function testCaptureFlushIntervalCanBeConfiguredInSeconds(): void
    {
        $mockClock = new MockClock(new \DateTimeImmutable('2022-05-01 00:00:00'));
        Clock::set($mockClock);

        try {
            $httpClient = new MockedHttpClient("app.posthog.com");
            $client = new Client(
                self::FAKE_API_KEY,
                [
                    "debug" => true,
                    "batch_size" => 100,
                    "flush_interval_seconds" => 1,
                ],
                $httpClient,
                null,
                false
            );

            $this->assertTrue($client->capture(["distinctId" => "john", "event" => "one"]));
            $mockClock->sleep(1);
            $this->assertTrue($client->capture(["distinctId" => "john", "event" => "two"]));

            $batchCalls = array_values(array_filter(
                $httpClient->calls ?? [],
                static fn(array $call): bool => ($call["path"] ?? null) === "/batch/"
            ));
            $this->assertCount(1, $batchCalls);
        } finally {
            Clock::set(new NativeClock());
        }
    }

    public function testCaptureFlushIntervalZeroFlushesImmediately(): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
                "batch_size" => 100,
                "flush_interval_seconds" => 0,
            ],
            $httpClient,
            null,
            false
        );

        $this->assertTrue($client->capture(["distinctId" => "john", "event" => "one"]));

        $batchCalls = array_values(array_filter(
            $httpClient->calls ?? [],
            static fn(array $call): bool => ($call["path"] ?? null) === "/batch/"
        ));
        $this->assertCount(1, $batchCalls);

        $payload = json_decode($batchCalls[0]["payload"], true);
        $this->assertSame(["one"], array_column($payload["batch"], "event"));
    }

    public static function invalidCaptureFlushIntervalCases(): array
    {
        return [
            'negative interval' => [-1],
            'numeric string interval' => ['1'],
            'non-finite interval' => [INF],
        ];
    }

    /**
     * @dataProvider invalidCaptureFlushIntervalCases
     */
    public function testInvalidCaptureFlushIntervalDefaultsToFiveSeconds(mixed $flushInterval): void
    {
        $mockClock = new MockClock(new \DateTimeImmutable('2022-05-01 00:00:00'));
        Clock::set($mockClock);

        try {
            $httpClient = new MockedHttpClient("app.posthog.com");
            $client = new Client(
                self::FAKE_API_KEY,
                [
                    "debug" => true,
                    "batch_size" => 100,
                    "flush_interval_seconds" => $flushInterval,
                ],
                $httpClient,
                null,
                false
            );

            $this->assertTrue($client->capture(["distinctId" => "john", "event" => "one"]));
            $mockClock->sleep(4);
            $this->assertTrue($client->capture(["distinctId" => "john", "event" => "two"]));
            $this->assertSame([], $httpClient->calls ?? []);

            $mockClock->sleep(1);
            $this->assertTrue($client->capture(["distinctId" => "john", "event" => "three"]));

            $batchCalls = array_values(array_filter(
                $httpClient->calls ?? [],
                static fn(array $call): bool => ($call["path"] ?? null) === "/batch/"
            ));
            $this->assertCount(1, $batchCalls);
        } finally {
            Clock::set(new NativeClock());
        }
    }

    /**
     * @dataProvider validTopLevelUuidCases
     */
    public function testCaptureKeepsValidTopLevelUuid(string $uuid): void
    {
        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "john",
                    "event" => "Module PHP Event",
                    "uuid" => $uuid,
                )
            )
        );
        PostHog::flush();

        $event = $this->firstBatchEvent();

        self::assertSame($uuid, $event['uuid']);
    }

    /**
     * @dataProvider invalidTopLevelUuidCases
     */
    public function testCaptureReplacesInvalidTopLevelUuid(mixed $uuid): void
    {
        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "john",
                    "event" => "Module PHP Event",
                    "uuid" => $uuid,
                )
            )
        );
        PostHog::flush();

        $event = $this->firstBatchEvent();

        self::assertNotSame($uuid, $event['uuid']);
        $this->assertValidUuidV4($event['uuid']);
    }

    /**
     * @dataProvider invalidTopLevelUuidCases
     */
    public function testRawReplacesInvalidTopLevelUuid(mixed $uuid): void
    {
        $this->client->raw(
            array(
                "event" => "Raw Event",
                "uuid" => $uuid,
            )
        );
        PostHog::flush();

        $event = $this->firstBatchEvent();

        self::assertNotSame($uuid, $event['uuid']);
        $this->assertValidUuidV4($event['uuid']);
    }

    public function testCaptureIncludesIsServerProperty(): void
    {
        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "john",
                    "event" => "Module PHP Event",
                )
            )
        );
        PostHog::flush();

        $batchCall = null;
        foreach ($this->http_client->calls as $call) {
            if (($call["path"] ?? null) === "/batch/") {
                $batchCall = $call;
                break;
            }
        }
        self::assertNotNull($batchCall, "Expected a /batch/ call to have been made");

        $decoded = json_decode($batchCall["payload"], true);
        $properties = $decoded["batch"][0]["properties"];

        self::assertArrayHasKey('$is_server', $properties);
        self::assertTrue($properties['$is_server']);
    }

    public function testCaptureStripsDeprecatedTopLevelBatchFields(): void
    {
        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "john",
                    "event" => "Module PHP Event",
                    "type" => "capture",
                    "library" => "custom-lib",
                    "library_version" => "0.0.1",
                    "library_consumer" => "CustomConsumer",
                    "send_feature_flags" => false,
                )
            )
        );
        PostHog::flush();

        $batchCall = null;
        foreach ($this->http_client->calls as $call) {
            if (($call["path"] ?? null) === "/batch/") {
                $batchCall = $call;
                break;
            }
        }
        self::assertNotNull($batchCall, "Expected a /batch/ call to have been made");

        $decoded = json_decode($batchCall["payload"], true);
        $event = $decoded["batch"][0];

        self::assertArrayNotHasKey('type', $event);
        self::assertSame('Module PHP Event', $event['event']);
        self::assertArrayNotHasKey('library', $event);
        self::assertArrayNotHasKey('library_version', $event);
        self::assertArrayNotHasKey('library_consumer', $event);
        self::assertArrayNotHasKey('send_feature_flags', $event);
        self::assertSame(array('User-Agent: posthog-php/' . PostHog::VERSION), $batchCall['extraHeaders']);
        self::assertSame('custom-lib', $event['properties']['$lib']);
        self::assertSame('0.0.1', $event['properties']['$lib_version']);
        self::assertSame('CustomConsumer', $event['properties']['$lib_consumer']);
    }

    public function testCaptureCanonicalSdkPropertiesOverrideDeprecatedTopLevelFields(): void
    {
        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "john",
                    "event" => "Module PHP Event",
                    "library" => "custom-lib",
                    "library_version" => "0.0.1",
                    "library_consumer" => "CustomConsumer",
                    "properties" => array(
                        '$lib' => 'canonical-lib',
                        '$lib_version' => '1.2.3',
                        '$lib_consumer' => 'CanonicalConsumer',
                    ),
                )
            )
        );
        PostHog::flush();

        $batchCall = null;
        foreach ($this->http_client->calls as $call) {
            if (($call["path"] ?? null) === "/batch/") {
                $batchCall = $call;
                break;
            }
        }
        self::assertNotNull($batchCall, "Expected a /batch/ call to have been made");

        $decoded = json_decode($batchCall["payload"], true);
        $event = $decoded["batch"][0];

        self::assertArrayNotHasKey('library', $event);
        self::assertArrayNotHasKey('library_version', $event);
        self::assertArrayNotHasKey('library_consumer', $event);
        self::assertSame(array('User-Agent: posthog-php/' . PostHog::VERSION), $batchCall['extraHeaders']);
        self::assertSame('canonical-lib', $event['properties']['$lib']);
        self::assertSame('1.2.3', $event['properties']['$lib_version']);
        self::assertSame('CanonicalConsumer', $event['properties']['$lib_consumer']);
    }

    public function testCaptureOmitsIsServerPropertyWhenDisabled(): void
    {
        $this->http_client = new MockedHttpClient("app.posthog.com");
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
                "is_server" => false,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "john",
                    "event" => "Module PHP Event",
                )
            )
        );
        PostHog::flush();

        $batchCall = null;
        foreach ($this->http_client->calls as $call) {
            if (($call["path"] ?? null) === "/batch/") {
                $batchCall = $call;
                break;
            }
        }
        self::assertNotNull($batchCall, "Expected a /batch/ call to have been made");

        $decoded = json_decode($batchCall["payload"], true);
        $properties = $decoded["batch"][0]["properties"];

        self::assertArrayNotHasKey('$is_server', $properties);
    }

    public function testCaptureWithSendFeatureFlagsOption(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_MULTIPLE_REQUEST);
            $this->client = new Client(
                self::FAKE_API_KEY,
                [
                    "debug" => true,
                    "feature_flag_request_timeout_ms" => 1234,
                ],
                $this->http_client,
                "test"
            );
            PostHog::init(null, null, $this->client);

            $this->assertTrue(
                PostHog::capture(
                    array (
                        "distinctId" => "john",
                        "event" => "Module PHP Event",
                        "send_feature_flags" => true
                    )
                )
            );
            PostHog::flush();

            $this->assertAndStripBatchUuid(1);
            $this->assertEquals(
                $this->http_client->calls,
                array (
                    0 => array (
                        "path" => "/flags/definitions?send_cohorts&token=random_key",
                        "payload" => null,
                        "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION, 1 => 'Authorization: Bearer test'),
                        "requestOptions" => array("includeEtag" => true),
                    ),
                    1 => array (
                        "path" => "/batch/",
                        "payload" => '{"batch":[{"event":"Module PHP Event","properties":{"$feature\/true-flag":true,"$active_feature_flags":["true-flag"],"$lib":"posthog-php","$lib_version":"' . PostHog::VERSION . '","$lib_consumer":"LibCurl","$is_server":true},"distinct_id":"john","groups":[],"timestamp":"2022-05-01T00:00:00+00:00"}],"api_key":"random_key"}',
                        "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                        "requestOptions" => array('shouldVerify' => true),
                    ),
                )
            );

            // Verify only locally evaluated feature flags are included
            $this->assertEquals(
                strpos($this->http_client->calls[1]["payload"], 'simpleFlag'),
                false
            );
            $this->assertEquals(
                strpos($this->http_client->calls[1]["payload"], 'true-flag'),
                true
            );
        });
    }

    public function testCaptureWithLocalSendFlags(): void
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_MULTIPLE_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            $this->assertTrue(
                PostHog::capture(
                    array (
                        "distinctId" => "john",
                        "event" => "Module PHP Event",
                        "send_feature_flags" => true
                    ),
                )
            );

            PostHog::flush();

            $this->assertAndStripBatchUuid(1);
            $this->assertEquals(
                $this->http_client->calls,
                array (
                    0 => array (
                        "path" => "/flags/definitions?send_cohorts&token=random_key",
                        "payload" => null,
                        "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION, 1 => 'Authorization: Bearer test'),
                        "requestOptions" => array("includeEtag" => true),
                    ),
                    1 => array (
                        "path" => "/batch/",
                        "payload" => '{"batch":[{"event":"Module PHP Event","properties":{"$feature\/true-flag":true,"$active_feature_flags":["true-flag"],"$lib":"posthog-php","$lib_version":"' . PostHog::VERSION . '","$lib_consumer":"LibCurl","$is_server":true},"distinct_id":"john","groups":[],"timestamp":"2022-05-01T00:00:00+00:00"}],"api_key":"random_key"}',
                        "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                        "requestOptions" => array('shouldVerify' => true),
                    ),
                )
            );
        });
    }

    public function testCaptureWithLocalSendFlagsNoOverrides(): void
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_MULTIPLE_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            $this->assertTrue(
                PostHog::capture(
                    array (
                        "distinctId" => "john",
                        "event" => "Module PHP Event",
                        "properties" => array (
                            "\$feature/true-flag" => "random-override"
                        ),
                        "send_feature_flags" => true
                    )
                )
            );

            PostHog::flush();

            $this->assertAndStripBatchUuid(1);
            $this->assertEquals(
                $this->http_client->calls,
                array (
                    0 => array (
                        "path" => "/flags/definitions?send_cohorts&token=random_key",
                        "payload" => null,
                        "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION, 1 => 'Authorization: Bearer test'),
                        "requestOptions" => array("includeEtag" => true),

                    ),
                    1 => array (
                        "path" => "/batch/",
                        "payload" => '{"batch":[{"event":"Module PHP Event","properties":{"$feature\/true-flag":"random-override","$active_feature_flags":["true-flag"],"$lib":"posthog-php","$lib_version":"' . PostHog::VERSION . '","$lib_consumer":"LibCurl","$is_server":true},"distinct_id":"john","groups":[],"timestamp":"2022-05-01T00:00:00+00:00"}],"api_key":"random_key"}',
                        "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                        "requestOptions" => array('shouldVerify' => true),
                    ),
                )
            );
        });
    }

    public function testIdentify(): void
    {
        self::assertTrue(
            PostHog::identify(
                array(
                    "distinctId" => "doe",
                    "properties" => array(
                        "loves_php" => false,
                        "birthday" => time(),
                    ),
                )
            )
        );
    }

    public function testEmptyProperties(): void
    {
        self::assertTrue(
            PostHog::identify(
                array(
                    "distinctId" => "empty-properties",
                )
            )
        );

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "empty-properties",
                )
            )
        );
    }

    public function testEmptyArrayProperties(): void
    {
        self::assertTrue(
            PostHog::identify(
                array(
                    "distinctId" => "empty-properties",
                    "properties" => array(),
                )
            )
        );

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "empty-properties",
                    "properties" => array(),
                )
            )
        );
    }

    public function testAlias(): void
    {
        self::assertTrue(
            PostHog::alias(
                array(
                    "alias" => "previous-id",
                    "distinctId" => "user-id",
                )
            )
        );
    }

    public function testAliasReturnsBooleanWhenBatchFlushesImmediately(): void
    {
        $httpClient = new MockedHttpClient("app.posthog.com");
        $client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
                "batch_size" => 1,
            ],
            $httpClient
        );
        PostHog::init(null, null, $client);

        $result = PostHog::alias(
            array(
                "alias" => "previous-id",
                "distinctId" => "user-id",
            )
        );

        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    public function testTimestamps(): void
    {
        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "integer-timestamp",
                    "timestamp" => (int) mktime(0, 0, 0, date('n'), 1, date('Y')),
                )
            )
        );

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "string-integer-timestamp",
                    "timestamp" => (string) mktime(0, 0, 0, date('n'), 1, date('Y')),
                )
            )
        );

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "iso8630-timestamp",
                    "timestamp" => date(DATE_ATOM, mktime(0, 0, 0, date('n'), 1, date('Y'))),
                )
            )
        );

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "iso8601-timestamp",
                    "timestamp" => date(DATE_ATOM, mktime(0, 0, 0, date('n'), 1, date('Y'))),
                )
            )
        );

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "strtotime-timestamp",
                    "timestamp" => strtotime('1 week ago'),
                )
            )
        );
    }

    public function testGroupIdentify(): void
    {
        self::assertTrue(
            PostHog::groupIdentify(
                array(
                    "groupType" => "company",
                    "groupKey" => "id:5",
                    "properties" => array(
                        "foo" => "bar"
                    )
                )
            )
        );

        self::assertTrue(
            PostHog::groupIdentify(
                array(
                    "groupType" => "company",
                    "groupKey" => "id:5",
                )
            )
        );
    }

    public function testGroupIdentifyValidation(): void
    {
        try {
            Posthog::groupIdentify(array());
        } catch (Exception $e) {
            $this->assertEquals("PostHog::groupIdentify() expects a groupType", $e->getMessage());
        }
    }

    public function testDefaultPropertiesGetAddedProperly(): void
    {
        PostHog::getFeatureFlag('random_key', 'some_id', array("company" => "id:5", "instance" => "app.posthog.com"), array("x1" => "y1"), array("company" => array("x" => "y")));
        $this->assertEquals(
            $this->http_client->calls,
            array(
                0 => array(
                    "path" => "/flags/definitions?send_cohorts&token=random_key",
                    "payload" => null,
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION, 1 => 'Authorization: Bearer test'),
                    "requestOptions" => array("includeEtag" => true),
                ),
                1 => array(
                    "path" => "/flags/?v=2",
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"some_id","groups":{"company":"id:5","instance":"app.posthog.com"},"person_properties":{"x1":"y1"},"group_properties":{"company":{"$group_key":"id:5","x":"y"},"instance":{"$group_key":"app.posthog.com"}},"geoip_disable":false,"flag_keys_to_evaluate":["random_key"]}', self::FAKE_API_KEY),
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                    "requestOptions" => array("timeout" => 3000, "shouldRetry" => false),
                ),
            )
        );

        // reset calls
        $this->http_client->calls = array();

        PostHog::getFeatureFlag(
            'random_key',
            'some_id',
            array("company" => "id:5", "instance" => "app.posthog.com"),
            array("distinct_id" => "override"),
            array("company" => array("\$group_key" => "group_override"), "instance" => array("\$group_key" => "app.posthog.com"))
        );
        $this->assertEquals(
            $this->http_client->calls,
            array(
                0 => array(
                    "path" => "/flags/?v=2",
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"some_id","groups":{"company":"id:5","instance":"app.posthog.com"},"person_properties":{"distinct_id":"override"},"group_properties":{"company":{"$group_key":"group_override"},"instance":{"$group_key":"app.posthog.com"}},"geoip_disable":false,"flag_keys_to_evaluate":["random_key"]}', self::FAKE_API_KEY),
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                    "requestOptions" => array("timeout" => 3000, "shouldRetry" => false),
                ),
            )
        );
        // reset calls
        $this->http_client->calls = array();

        # test empty
        PostHog::getFeatureFlag('random_key', 'some_id', array("company" => "id:5"), [], []);
        $this->assertEquals(
            $this->http_client->calls,
            array(
                0 => array(
                    "path" => "/flags/?v=2",
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"some_id","groups":{"company":"id:5"},"person_properties":{},"group_properties":{"company":{"$group_key":"id:5"}},"geoip_disable":false,"flag_keys_to_evaluate":["random_key"]}', self::FAKE_API_KEY),
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                    "requestOptions" => array("timeout" => 3000, "shouldRetry" => false),
                ),
            )
        );

        // reset calls
        $this->http_client->calls = array();

        PostHog::isFeatureEnabled('random_key', 'some_id', array("company" => "id:5", "instance" => "app.posthog.com"), array("x1" => "y1"), array("company" => array("x" => "y")));
        $this->assertEquals(
            $this->http_client->calls,
            array(
                0 => array(
                    "path" => "/flags/?v=2",
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"some_id","groups":{"company":"id:5","instance":"app.posthog.com"},"person_properties":{"x1":"y1"},"group_properties":{"company":{"$group_key":"id:5","x":"y"},"instance":{"$group_key":"app.posthog.com"}},"geoip_disable":false,"flag_keys_to_evaluate":["random_key"]}', self::FAKE_API_KEY),
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                    "requestOptions" => array("timeout" => 3000, "shouldRetry" => false),
                ),
            )
        );
    }

    public function testCaptureWithSendFeatureFlagsFalse(): void
    {
        $this->http_client = new MockedHttpClient(host: "app.posthog.com", flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_MULTIPLE_REQUEST);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        $this->executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            $this->assertTrue(
                PostHog::capture(
                    array (
                        "distinctId" => "john",
                        "event" => "Module PHP Event",
                        "send_feature_flags" => false
                    )
                )
            );

            PostHog::flush();

            $this->assertAndStripBatchUuid(1);
            // When send_feature_flags is explicitly false, NO feature flags should be added
            $this->assertEquals(
                $this->http_client->calls,
                array (
                    0 => array (
                        "path" => "/flags/definitions?send_cohorts&token=random_key",
                        "payload" => null,
                        "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION, 1 => 'Authorization: Bearer test'),
                        "requestOptions" => array("includeEtag" => true),
                    ),
                    1 => array (
                        "path" => "/batch/",
                        "payload" => '{"batch":[{"event":"Module PHP Event","properties":{"$lib":"posthog-php","$lib_version":"' . PostHog::VERSION . '","$lib_consumer":"LibCurl","$is_server":true},"distinct_id":"john","groups":[],"timestamp":"2022-05-01T00:00:00+00:00"}],"api_key":"random_key"}',
                        "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                        "requestOptions" => array('shouldVerify' => true),
                    ),
                )
            );

            // Verify NO feature flag properties were added
            $payload = $this->http_client->calls[1]["payload"];
            $this->assertStringNotContainsString('$feature/', $payload);
            $this->assertStringNotContainsString('$active_feature_flags', $payload);
        });
    }
}
