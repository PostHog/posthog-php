<?php

namespace PostHog\Test;

// comment out below to print all logs instead of failing tests
require_once 'test/error_log_mock.php';

use Exception;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\PostHog;
use PostHog\Test\Assets\MockedResponses;


class FeatureFlagTest extends TestCase
{
    use ClockMockTrait;

    const FAKE_API_KEY = "random_key";

    private $http_client;
    private $client;

    public function setUp($flagsEndpointResponse = MockedResponses::FLAGS_RESPONSE, $personalApiKey = "test"): void
    {
        date_default_timezone_set("UTC");
        $this->http_client = new MockedHttpClient("app.posthog.com", flagsEndpointResponse: $flagsEndpointResponse);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
            $personalApiKey
        );
        PostHog::init(null, null, $this->client);

        // Reset the errorMessages array before each test
        global $errorMessages;
        $errorMessages = [];
    }

    public function checkEmptyErrorLogs(): void
    {
        global $errorMessages;
        $this->assertTrue(empty($errorMessages), "Error logs are not empty: " . implode("\n", $errorMessages));
    }

    private function assertAndStripBatchUuid(int $callIndex): void
    {
        $payload = json_decode($this->http_client->calls[$callIndex]['payload'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('batch', $payload);

        foreach ($payload['batch'] as $index => $event) {
            $this->assertArrayHasKey('uuid', $event);
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $event['uuid']
            );
            unset($payload['batch'][$index]['uuid']);
        }

        $this->http_client->calls[$callIndex]['payload'] = json_encode($payload);
    }

    public static function decideResponseCases(): array
    {
        return [
            'v3 response' => [MockedResponses::FLAGS_RESPONSE],
            'v4 response' => [MockedResponses::FLAGS_V2_RESPONSE]
        ];
    }

    public static function nonRetryableFlagsStatusCodes(): array
    {
        return [
            'request timeout' => [408],
            'rate limited' => [429],
            'server error' => [500],
        ];
    }

    public static function retryableFlagsStatusCodes(): array
    {
        return [
            'bad gateway' => [502],
            'gateway timeout' => [504],
        ];
    }

    public static function retryableFlagsCurlErrors(): array
    {
        return [
            'operation timed out' => [28],
            'got nothing' => [52],
            'receive error' => [56],
        ];
    }

    /**
     * @dataProvider retryableFlagsCurlErrors
     */
    public function testFlagsRequestRetriesTransientCurlErrors(int $curlErrno): void
    {
        $this->http_client = new MockedHttpClient("app.posthog.com");
        $this->http_client->setFlagsEndpointResponseQueue([
            ['response' => [], 'responseCode' => 0, 'curlErrno' => $curlErrno],
            ['response' => [], 'responseCode' => 0, 'curlErrno' => $curlErrno],
            ['response' => MockedResponses::FLAGS_RESPONSE, 'responseCode' => 200, 'curlErrno' => 0],
        ]);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
                "feature_flag_request_max_retries" => 2,
                "maximum_backoff_duration" => 101,
            ],
            $this->http_client,
            null
        );

        $response = $this->client->flags('user-id');

        $this->assertTrue($response['featureFlags']['simpleFlag']);
        $this->assertCount(3, $this->http_client->calls);
        foreach ($this->http_client->calls as $call) {
            $this->assertSame('/flags/?v=2', $call['path']);
            $this->assertEquals(["timeout" => 3000, "shouldRetry" => false], $call['requestOptions']);
        }
    }

    /**
     * @dataProvider retryableFlagsCurlErrors
     */
    public function testFlagsRequestStopsAfterExhaustingTransientCurlErrorRetries(int $curlErrno): void
    {
        $this->http_client = new MockedHttpClient("app.posthog.com");
        $this->http_client->setFlagsEndpointResponseQueue([
            ['response' => [], 'responseCode' => 0, 'curlErrno' => $curlErrno],
            ['response' => [], 'responseCode' => 0, 'curlErrno' => $curlErrno],
            ['response' => [], 'responseCode' => 0, 'curlErrno' => $curlErrno],
            ['response' => MockedResponses::FLAGS_RESPONSE, 'responseCode' => 200, 'curlErrno' => 0],
        ]);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
                "feature_flag_request_max_retries" => 2,
                "maximum_backoff_duration" => 101,
            ],
            $this->http_client,
            null
        );

        $this->assertSame([
            'featureFlags' => [],
            'featureFlagPayloads' => [],
            'flags' => [],
        ], $this->client->flags('user-id'));
        $this->assertCount(3, $this->http_client->calls);
    }

    public function testFlagsRequestDoesNotRetryWhenConfiguredMaxRetriesIsZero(): void
    {
        $this->http_client = new MockedHttpClient("app.posthog.com");
        $this->http_client->setFlagsEndpointResponseQueue([
            ['response' => [], 'responseCode' => 0, 'curlErrno' => 28],
            ['response' => MockedResponses::FLAGS_RESPONSE, 'responseCode' => 200, 'curlErrno' => 0],
        ]);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
                "feature_flag_request_max_retries" => 0,
                "maximum_backoff_duration" => 101,
            ],
            $this->http_client,
            null
        );

        $this->assertSame([
            'featureFlags' => [],
            'featureFlagPayloads' => [],
            'flags' => [],
        ], $this->client->flags('user-id'));
        $this->assertCount(1, $this->http_client->calls);
    }

    public function testFlagsRequestDoesNotRetryConnectionRefused(): void
    {
        $this->http_client = new MockedHttpClient("app.posthog.com");
        $this->http_client->setFlagsEndpointResponseQueue([
            ['response' => [], 'responseCode' => 0, 'curlErrno' => 7],
            ['response' => MockedResponses::FLAGS_RESPONSE, 'responseCode' => 200, 'curlErrno' => 0],
        ]);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
                "maximum_backoff_duration" => 101,
            ],
            $this->http_client,
            null
        );

        $this->assertSame([
            'featureFlags' => [],
            'featureFlagPayloads' => [],
            'flags' => [],
        ], $this->client->flags('user-id'));
        $this->assertCount(1, $this->http_client->calls);
    }

    /**
     * @dataProvider nonRetryableFlagsStatusCodes
     */
    public function testFlagsRequestDoesNotRetryHttpStatusErrors(int $statusCode): void
    {
        $this->http_client = new MockedHttpClient("app.posthog.com");
        $this->http_client->setFlagsEndpointResponseQueue([
            ['response' => [], 'responseCode' => $statusCode, 'curlErrno' => 0],
            ['response' => MockedResponses::FLAGS_RESPONSE, 'responseCode' => 200, 'curlErrno' => 0],
        ]);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
                "maximum_backoff_duration" => 101,
            ],
            $this->http_client,
            null
        );

        $this->assertSame([
            'featureFlags' => [],
            'featureFlagPayloads' => [],
            'flags' => [],
        ], $this->client->flags('user-id'));
        $this->assertCount(1, $this->http_client->calls);
        $this->assertSame('/flags/?v=2', $this->http_client->calls[0]['path']);
    }

    /**
     * @dataProvider retryableFlagsStatusCodes
     */
    public function testFlagsRequestRetriesHttp502And504(int $statusCode): void
    {
        $this->http_client = new MockedHttpClient("app.posthog.com");
        $this->http_client->setFlagsEndpointResponseQueue([
            ['response' => [], 'responseCode' => $statusCode, 'curlErrno' => 0],
            ['response' => MockedResponses::FLAGS_RESPONSE, 'responseCode' => 200, 'curlErrno' => 0],
        ]);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
                "maximum_backoff_duration" => 101,
            ],
            $this->http_client,
            null
        );

        $response = $this->client->flags('user-id');

        $this->assertTrue($response['featureFlags']['simpleFlag']);
        $this->assertCount(2, $this->http_client->calls);
        foreach ($this->http_client->calls as $call) {
            $this->assertSame('/flags/?v=2', $call['path']);
            $this->assertEquals(["timeout" => 3000, "shouldRetry" => false], $call['requestOptions']);
        }
    }

    /**
     * @dataProvider retryableFlagsStatusCodes
     */
    public function testFlagsRequestStopsAfterExhaustingHttp502And504Retries(int $statusCode): void
    {
        $this->http_client = new MockedHttpClient("app.posthog.com");
        $this->http_client->setFlagsEndpointResponseQueue([
            ['response' => [], 'responseCode' => $statusCode, 'curlErrno' => 0],
            ['response' => [], 'responseCode' => $statusCode, 'curlErrno' => 0],
            ['response' => [], 'responseCode' => $statusCode, 'curlErrno' => 0],
            ['response' => MockedResponses::FLAGS_RESPONSE, 'responseCode' => 200, 'curlErrno' => 0],
        ]);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
                "feature_flag_request_max_retries" => 2,
                "maximum_backoff_duration" => 101,
            ],
            $this->http_client,
            null
        );

        $this->assertSame([
            'featureFlags' => [],
            'featureFlagPayloads' => [],
            'flags' => [],
        ], $this->client->flags('user-id'));
        $this->assertCount(3, $this->http_client->calls);
        foreach ($this->http_client->calls as $call) {
            $this->assertSame('/flags/?v=2', $call['path']);
            $this->assertEquals(["timeout" => 3000, "shouldRetry" => false], $call['requestOptions']);
        }
    }

    /**
     * @dataProvider retryableFlagsStatusCodes
     */
    public function testFlagsRequestDoesNotRetryHttp502And504WhenConfiguredMaxRetriesIsZero(int $statusCode): void
    {
        $this->http_client = new MockedHttpClient("app.posthog.com");
        $this->http_client->setFlagsEndpointResponseQueue([
            ['response' => [], 'responseCode' => $statusCode, 'curlErrno' => 0],
            ['response' => MockedResponses::FLAGS_RESPONSE, 'responseCode' => 200, 'curlErrno' => 0],
        ]);
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
                "feature_flag_request_max_retries" => 0,
                "maximum_backoff_duration" => 101,
            ],
            $this->http_client,
            null
        );

        $this->assertSame([
            'featureFlags' => [],
            'featureFlagPayloads' => [],
            'flags' => [],
        ], $this->client->flags('user-id'));
        $this->assertCount(1, $this->http_client->calls);
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testIsFeatureEnabled($response)
    {
        $this->setUp($response);
        $this->assertFalse(PostHog::isFeatureEnabled('having_fun', 'user-id'));
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
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"user-id","groups":{},"person_properties":{},"group_properties":{},"geoip_disable":false,"flag_keys_to_evaluate":["having_fun"]}', self::FAKE_API_KEY),
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                    "requestOptions" => array("timeout" => 3000, "shouldRetry" => false),
                ),
            )
        );
    }

    public function testIsFeatureEnabledCapturesFeatureFlagCalledEventWithAdditionalMetadata()
    {
        $this->executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            $this->setUp(MockedResponses::FLAGS_V2_RESPONSE, personalApiKey: null);
            $this->assertTrue(PostHog::isFeatureEnabled('simple-test', 'user-id'));
            PostHog::flush();
            $this->assertAndStripBatchUuid(1);
            $this->assertEquals(
                $this->http_client->calls,
                array(
                0 => array(
                    "path" => "/flags/?v=2",
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"user-id","groups":{},"person_properties":{},"group_properties":{},"geoip_disable":false,"flag_keys_to_evaluate":["simple-test"]}', self::FAKE_API_KEY),
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                        "requestOptions" => array("timeout" => 3000, "shouldRetry" => false),
                    ),
                1 => array(
                    "path" => "/batch/",
                    "payload" => '{"batch":[{"properties":{"$feature_flag":"simple-test","$feature_flag_response":true,"$feature_flag_request_id":"98487c8a-287a-4451-a085-299cd76228dd","$feature_flag_id":6,"$feature_flag_version":1,"$feature_flag_reason":"Matched condition set 1","$lib":"posthog-php","$lib_version":"' . PostHog::VERSION . '","$lib_consumer":"LibCurl","$is_server":true,"$groups":[]},"distinct_id":"user-id","event":"$feature_flag_called","$groups":[],"groups":[],"timestamp":"2022-05-01T00:00:00+00:00"}],"api_key":"random_key"}',
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                    "requestOptions" => array('shouldVerify' => true),
                    ),
                )
            );
        });
    }

    public function testWhitespacePersonalApiKeyFallsBackToFlagsEndpoint()
    {
        $this->setUp(MockedResponses::FLAGS_V2_RESPONSE, personalApiKey: " \n\t ");
        $this->assertTrue(PostHog::isFeatureEnabled('simple-test', 'user-id'));
        $this->assertEquals(
            [
                [
                    "path" => "/flags/?v=2",
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"user-id","groups":{},"person_properties":{},"group_properties":{},"geoip_disable":false,"flag_keys_to_evaluate":["simple-test"]}', self::FAKE_API_KEY),
                    "extraHeaders" => [0 => 'User-Agent: posthog-php/' . PostHog::VERSION],
                    "requestOptions" => ["timeout" => 3000, "shouldRetry" => false],
                ],
            ],
            $this->http_client->calls
        );
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testIsFeatureEnabledGroups($response)
    {
        $this->setUp($response);
        $this->assertFalse(PostHog::isFeatureEnabled('having_fun', 'user-id', array("company" => "id:5")));

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
                    "payload" => sprintf(
                        '{"api_key":"%s","distinct_id":"user-id","groups":{"company":"id:5"},"person_properties":{},"group_properties":{"company":{"$group_key":"id:5"}},"geoip_disable":false,"flag_keys_to_evaluate":["having_fun"]}',
                        self::FAKE_API_KEY
                    ),
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                    "requestOptions" => array("timeout" => 3000, "shouldRetry" => false),
                ),
            )
        );
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlag($response)
    {
        $this->setUp($response);
        $this->assertEquals("variant-value", PostHog::getFeatureFlag('multivariate-test', 'user-id'));
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
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"user-id","groups":{},"person_properties":{},"group_properties":{},"geoip_disable":false,"flag_keys_to_evaluate":["multivariate-test"]}', self::FAKE_API_KEY),
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                    "requestOptions" => array("timeout" => 3000, "shouldRetry" => false),
                ),
            )
        );
    }

    public function testGetFeatureFlagCapturesFeatureFlagCalledEventWithAdditionalMetadata()
    {
        $this->executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            $this->setUp(MockedResponses::FLAGS_V2_RESPONSE, personalApiKey: null);
            $this->assertEquals("variant-value", PostHog::getFeatureFlag('multivariate-test', 'user-id'));
            PostHog::flush();
            $this->assertAndStripBatchUuid(1);
            $this->assertEquals(
                $this->http_client->calls,
                array(
                0 => array(
                    "path" => "/flags/?v=2",
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"user-id","groups":{},"person_properties":{},"group_properties":{},"geoip_disable":false,"flag_keys_to_evaluate":["multivariate-test"]}', self::FAKE_API_KEY),
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                    "requestOptions" => array("timeout" => 3000, "shouldRetry" => false),
                ),
                1 => array(
                    "path" => "/batch/",
                    "payload" => '{"batch":[{"properties":{"$feature_flag":"multivariate-test","$feature_flag_response":"variant-value","$feature_flag_request_id":"98487c8a-287a-4451-a085-299cd76228dd","$feature_flag_id":7,"$feature_flag_version":3,"$feature_flag_reason":"Matched condition set 2","$lib":"posthog-php","$lib_version":"' . PostHog::VERSION . '","$lib_consumer":"LibCurl","$is_server":true,"$groups":[]},"distinct_id":"user-id","event":"$feature_flag_called","$groups":[],"groups":[],"timestamp":"2022-05-01T00:00:00+00:00"}],"api_key":"random_key"}',
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                    "requestOptions" => array('shouldVerify' => true),
                ),
                )
            );
        });
    }

    public static function hasExperimentCases(): array
    {
        // The event property mirrors the server's has_experiment field exactly and is
        // omitted when the server does not report it (older deployments).
        return [
            'reported true' => [true],
            'reported false' => [false],
            'absent' => [null],
        ];
    }

    /**
     * @dataProvider hasExperimentCases
     */
    public function testFeatureFlagCalledEventIncludesHasExperimentFromRemoteMetadata(?bool $reported)
    {
        $response = MockedResponses::FLAGS_V2_RESPONSE;
        if ($reported !== null) {
            $response['flags']['simple-test']['metadata']['has_experiment'] = $reported;
        }
        $this->setUp($response, personalApiKey: null);

        $this->assertTrue(PostHog::isFeatureEnabled('simple-test', 'user-id'));
        PostHog::flush();

        $payload = json_decode($this->http_client->calls[1]['payload'], true);
        $properties = $payload['batch'][0]['properties'];
        $this->assertSame('simple-test', $properties['$feature_flag']);
        if ($reported === null) {
            $this->assertArrayNotHasKey('$feature_flag_has_experiment', $properties);
        } else {
            $this->assertSame($reported, $properties['$feature_flag_has_experiment']);
        }
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagDefault($response)
    {
        $this->setUp($response);
        $this->assertEquals(PostHog::getFeatureFlag('blah', 'user-id'), null);

        $this->checkEmptyErrorLogs();
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagGroups($response)
    {
        $this->setUp($response);
        $this->assertEquals(
            "variant-value",
            PostHog::getFeatureFlag('multivariate-test', 'user-id', array("company" => "id:5"))
        );

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
                    "payload" => sprintf(
                        '{"api_key":"%s","distinct_id":"user-id","groups":{"company":"id:5"},"person_properties":{},"group_properties":{"company":{"$group_key":"id:5"}},"geoip_disable":false,"flag_keys_to_evaluate":["multivariate-test"]}',
                        self::FAKE_API_KEY
                    ),
                    "extraHeaders" => array(0 => 'User-Agent: posthog-php/' . PostHog::VERSION),
                    "requestOptions" => array("timeout" => 3000, "shouldRetry" => false),
                ),
            )
        );
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testfetchFeatureVariants($response)
    {
        $this->setUp($response);
        $this->assertIsArray(PostHog::fetchFeatureVariants('user-id'));
    }

     /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagPayloadHandlesJson($response): void
    {
        $this->setUp($response);

        $this->assertSame(['key' => 'value'], PostHog::getFeatureFlagPayload('json-payload', 'some-distinct'));
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagPayloadHandlesIntegers($response): void
    {
        $this->setUp($response);

        $this->assertSame(2500, PostHog::getFeatureFlagPayload('integer-payload', 'some-distinct'));
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagPayloadHandlesString($response): void
    {
        $this->setUp($response);

        $this->assertSame('A String', PostHog::getFeatureFlagPayload('string-payload', 'some-distinct'));
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagPayloadHandlesArray($response): void
    {
        $this->setUp($response);

        $this->assertSame([1, 2, 3], PostHog::getFeatureFlagPayload('array-payload', 'some-distinct'));
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagPayloadHandlesFlagDisabled($response): void
    {
        $this->setUp($response);

        $this->assertNull(PostHog::getFeatureFlagPayload('disabled-flag', 'some-distinct'));
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagPayloadHandlesFlagNotInResults($response): void
    {
        $this->setUp($response);

        $this->assertNull(PostHog::getFeatureFlagPayload('non-existent-flag', 'some-distinct'));
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagResult($response): void
    {
        $this->setUp($response);

        $result = PostHog::getFeatureFlagResult('json-payload', 'user-id');

        $this->assertNotNull($result);
        $this->assertEquals('json-payload', $result->getKey());
        $this->assertTrue($result->isEnabled());
        $this->assertNull($result->getVariant());
        $this->assertEquals(['key' => 'value'], $result->getPayload());
        $this->assertTrue($result->getValue());
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagResultWithMultivariateFlag($response): void
    {
        $this->setUp($response);

        $result = PostHog::getFeatureFlagResult('multivariate-test', 'user-id');

        $this->assertNotNull($result);
        $this->assertEquals('multivariate-test', $result->getKey());
        $this->assertTrue($result->isEnabled());
        $this->assertEquals('variant-value', $result->getVariant());
        $this->assertEquals('variant-value', $result->getValue());
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagResultReturnsNullForMissingFlag($response): void
    {
        $this->setUp($response);

        $result = PostHog::getFeatureFlagResult('non-existent-flag', 'user-id');

        $this->assertNull($result);
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagResultForDisabledFlag($response): void
    {
        $this->setUp($response);

        $result = PostHog::getFeatureFlagResult('disabled-flag', 'user-id');

        $this->assertNotNull($result);
        $this->assertEquals('disabled-flag', $result->getKey());
        $this->assertFalse($result->isEnabled());
        $this->assertNull($result->getVariant());
        $this->assertFalse($result->getValue());
    }

    public function testGetFeatureFlagResultSendsEvent(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            $this->setUp(MockedResponses::FLAGS_V2_RESPONSE, personalApiKey: null);

            $result = PostHog::getFeatureFlagResult('json-payload', 'user-id');
            PostHog::flush();

            $this->assertNotNull($result);
            $this->assertEquals(['key' => 'value'], $result->getPayload());

            // Verify that the $feature_flag_called event was sent
            $batchCall = null;
            foreach ($this->http_client->calls as $call) {
                if ($call['path'] === '/batch/') {
                    $batchCall = $call;
                    break;
                }
            }
            $this->assertNotNull($batchCall, 'Expected a batch call to be made');

            $payload = json_decode($batchCall['payload'], true);
            $this->assertNotEmpty($payload['batch']);

            $event = $payload['batch'][0];
            $this->assertEquals('$feature_flag_called', $event['event']);
            $this->assertEquals('json-payload', $event['properties']['$feature_flag']);
            $this->assertTrue($event['properties']['$feature_flag_response']);
        });
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagResultWithMultivariateFlagAndPayload($response): void
    {
        $this->setUp($response);

        $result = PostHog::getFeatureFlagResult('multivariate-simple-test', 'user-id');

        $this->assertNotNull($result);
        $this->assertEquals('multivariate-simple-test', $result->getKey());
        $this->assertTrue($result->isEnabled());
        $this->assertEquals('variant-simple-value', $result->getVariant());
        $this->assertEquals('variant-simple-value', $result->getValue());
        $this->assertEquals('some string payload', $result->getPayload());
    }

    public function testGetFeatureFlagPayloadDoesNotSendEvent(): void
    {
        $this->setUp(MockedResponses::FLAGS_V2_RESPONSE, personalApiKey: null);

        $payload = PostHog::getFeatureFlagPayload('json-payload', 'user-id');
        PostHog::flush();

        $this->assertEquals(['key' => 'value'], $payload);

        // Verify that NO batch call was made (no event sent)
        $batchCall = null;
        foreach ($this->http_client->calls as $call) {
            if ($call['path'] === '/batch/') {
                $batchCall = $call;
                break;
            }
        }
        $this->assertNull($batchCall, 'Expected no batch call to be made for getFeatureFlagPayload');
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagResultForDisabledFlagWithPayload($response): void
    {
        $this->setUp($response);

        $result = PostHog::getFeatureFlagResult('disabled-flag-with-payload', 'user-id');

        $this->assertNotNull($result);
        $this->assertEquals('disabled-flag-with-payload', $result->getKey());
        $this->assertFalse($result->isEnabled());
        $this->assertNull($result->getVariant());
        $this->assertFalse($result->getValue());
        $this->assertEquals(['disabled' => true], $result->getPayload());
    }

    public function testGetFeatureFlagResultWithLocalEvaluationOnly(): void
    {
        // For local evaluation, we need to set flagEndpointResponse (not flagsEndpointResponse)
        $this->http_client = new MockedHttpClient(
            host: "app.posthog.com",
            flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_SIMPLE_REQUEST
        );
        $this->client = new Client(
            self::FAKE_API_KEY,
            ["debug" => true],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        // Flag can be evaluated locally - should return result
        $result = PostHog::getFeatureFlagResult(
            'simple-flag',
            'user-id',
            [],
            [],
            [],
            true  // onlyEvaluateLocally
        );

        $this->assertNotNull($result);
        $this->assertEquals('simple-flag', $result->getKey());
        $this->assertTrue($result->isEnabled());

        // Verify no decide (/flags/?v=2) call was made
        foreach ($this->http_client->calls as $call) {
            $this->assertFalse(str_starts_with($call['path'], '/flags/?'), 'Expected no decide call for local evaluation');
        }
    }

    public function testGetFeatureFlagResultReturnsNullForLocalEvaluationWhenFlagCannotBeEvaluatedLocally(): void
    {
        // Use a flag config that requires cohorts which can't be evaluated locally
        $this->http_client = new MockedHttpClient(
            host: "app.posthog.com",
            flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_WITH_COHORTS_REQUEST
        );
        $this->client = new Client(
            self::FAKE_API_KEY,
            ["debug" => true],
            $this->http_client,
            "test"
        );
        PostHog::init(null, null, $this->client);

        // beta-feature requires cohort evaluation which needs server
        // When onlyEvaluateLocally is true, should return null
        $result = PostHog::getFeatureFlagResult(
            'beta-feature',
            'user-id',
            [],
            [],
            [],
            true  // onlyEvaluateLocally
        );

        $this->assertNull($result);

        // Verify no decide (/flags/?v=2) call was made
        foreach ($this->http_client->calls as $call) {
            $this->assertFalse(str_starts_with($call['path'], '/flags/?'), 'Expected no decide call for local evaluation only');
        }
    }

    /**
     * @dataProvider decideResponseCases
     */
    public function testGetFeatureFlagResultWithGroups($response): void
    {
        $this->setUp($response);

        $result = PostHog::getFeatureFlagResult(
            'group-flag',
            'user-id',
            ['company' => 'id:5']
        );

        $this->assertNotNull($result);
        $this->assertEquals('group-flag', $result->getKey());
        $this->assertTrue($result->isEnabled());
        $this->assertEquals('decide-fallback-value', $result->getVariant());

        // Verify that groups were passed in the /flags/ request
        $flagsCall = null;
        foreach ($this->http_client->calls as $call) {
            if (str_starts_with($call['path'], '/flags/?')) {
                $flagsCall = $call;
                break;
            }
        }
        $this->assertNotNull($flagsCall, 'Expected a /flags/ call to be made');

        $payload = json_decode($flagsCall['payload'], true);
        $this->assertArrayHasKey('groups', $payload);
        $this->assertEquals(['company' => 'id:5'], $payload['groups']);
    }
}
