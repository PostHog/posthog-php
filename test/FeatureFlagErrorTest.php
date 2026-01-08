<?php
// phpcs:ignoreFile
namespace PostHog\Test;

// comment out below to print all logs instead of failing tests
require_once 'test/error_log_mock.php';

use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\FeatureFlagError;
use PostHog\PostHog;
use PostHog\Test\Assets\MockedResponses;

class FeatureFlagErrorTest extends TestCase
{
    use ClockMockTrait;
    public const FAKE_API_KEY = "random_key";

    private $http_client;
    private $client;

    public function setUp($flagsEndpointResponse = MockedResponses::FLAGS_RESPONSE, $personalApiKey = null): void
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

    public function testFlagMissingError()
    {
        self::executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            $this->setUp(MockedResponses::FLAGS_RESPONSE, personalApiKey: null);

            // Request a flag that doesn't exist in the response
            $result = PostHog::getFeatureFlag('non-existent-flag', 'user-id');
            $this->assertNull($result);

            PostHog::flush();

            // Check that the $feature_flag_called event includes the flag_missing error
            $calls = $this->http_client->calls;
            $this->assertCount(2, $calls);

            // First call is to /flags/
            $this->assertStringStartsWith("/flags/", $calls[0]['path']);

            // Second call is to /batch/ with the $feature_flag_called event
            $this->assertEquals("/batch/", $calls[1]['path']);
            $payload = json_decode($calls[1]['payload'], true);
            $event = $payload['batch'][0];

            $this->assertEquals('$feature_flag_called', $event['event']);
            $this->assertEquals('non-existent-flag', $event['properties']['$feature_flag']);
            $this->assertNull($event['properties']['$feature_flag_response']);
            $this->assertEquals(FeatureFlagError::FLAG_MISSING, $event['properties']['$feature_flag_error']);
        });
    }

    public function testErrorsWhileComputingFlagsError()
    {
        self::executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            // Create a response with errorsWhileComputingFlags set to true
            $responseWithErrors = array_merge(MockedResponses::FLAGS_RESPONSE, [
                'errorsWhileComputingFlags' => true
            ]);

            $this->setUp($responseWithErrors, personalApiKey: null);

            // Request a flag that exists in the response
            $result = PostHog::getFeatureFlag('simple-test', 'user-id');
            $this->assertTrue($result);

            PostHog::flush();

            // Check that the $feature_flag_called event includes the errors_while_computing_flags error
            $calls = $this->http_client->calls;
            $this->assertCount(2, $calls);

            $payload = json_decode($calls[1]['payload'], true);
            $event = $payload['batch'][0];

            $this->assertEquals('$feature_flag_called', $event['event']);
            $this->assertEquals('simple-test', $event['properties']['$feature_flag']);
            $this->assertTrue($event['properties']['$feature_flag_response']);
            $this->assertEquals(
                FeatureFlagError::ERRORS_WHILE_COMPUTING_FLAGS,
                $event['properties']['$feature_flag_error']
            );
        });
    }

    public function testMultipleErrors()
    {
        self::executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            // Create a response with errorsWhileComputingFlags set to true
            // and request a flag that doesn't exist
            $responseWithErrors = array_merge(MockedResponses::FLAGS_RESPONSE, [
                'errorsWhileComputingFlags' => true
            ]);

            $this->setUp($responseWithErrors, personalApiKey: null);

            // Request a flag that doesn't exist
            $result = PostHog::getFeatureFlag('non-existent-flag', 'user-id');
            $this->assertNull($result);

            PostHog::flush();

            // Check that the $feature_flag_called event includes both errors
            $calls = $this->http_client->calls;
            $this->assertCount(2, $calls);

            $payload = json_decode($calls[1]['payload'], true);
            $event = $payload['batch'][0];

            $this->assertEquals('$feature_flag_called', $event['event']);
            $this->assertEquals('non-existent-flag', $event['properties']['$feature_flag']);
            $this->assertNull($event['properties']['$feature_flag_response']);

            // Errors should be joined with commas
            $expectedErrors = FeatureFlagError::ERRORS_WHILE_COMPUTING_FLAGS . ',' . FeatureFlagError::FLAG_MISSING;
            $this->assertEquals($expectedErrors, $event['properties']['$feature_flag_error']);
        });
    }

    public function testNoErrorWhenFlagEvaluatesSuccessfully()
    {
        self::executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            $this->setUp(MockedResponses::FLAGS_RESPONSE, personalApiKey: null);

            // Request a flag that exists in the response
            $result = PostHog::getFeatureFlag('simple-test', 'user-id');
            $this->assertTrue($result);

            PostHog::flush();

            // Check that the $feature_flag_called event does NOT include an error
            $calls = $this->http_client->calls;
            $this->assertCount(2, $calls);

            $payload = json_decode($calls[1]['payload'], true);
            $event = $payload['batch'][0];

            $this->assertEquals('$feature_flag_called', $event['event']);
            $this->assertEquals('simple-test', $event['properties']['$feature_flag']);
            $this->assertTrue($event['properties']['$feature_flag_response']);

            // Should not have $feature_flag_error property
            $this->assertArrayNotHasKey('$feature_flag_error', $event['properties']);
        });
    }

    public function testUnknownErrorWhenExceptionThrown()
    {
        self::executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            // Create a mocked client that will throw an exception
            $this->http_client = new class ("app.posthog.com") extends MockedHttpClient {
                public function sendRequest(
                    string $path,
                    ?string $payload,
                    array $extraHeaders = [],
                    array $requestOptions = []
                ): \PostHog\HttpResponse {
                    if (!isset($this->calls)) {
                        $this->calls = [];
                    }
                    array_push($this->calls, array(
                        "path" => $path,
                        "payload" => $payload,
                        "extraHeaders" => $extraHeaders,
                        "requestOptions" => $requestOptions
                    ));

                    if (str_starts_with($path, "/flags/")) {
                        throw new \Exception("Network error");
                    }

                    return parent::sendRequest($path, $payload, $extraHeaders, $requestOptions);
                }
            };

            $this->client = new Client(
                self::FAKE_API_KEY,
                [
                    "debug" => true,
                ],
                $this->http_client,
                null
            );
            PostHog::init(null, null, $this->client);

            // Reset error messages
            global $errorMessages;
            $errorMessages = [];

            // Request a flag - this should trigger an exception
            $result = PostHog::getFeatureFlag('simple-test', 'user-id');
            $this->assertNull($result);

            PostHog::flush();

            // Check that the $feature_flag_called event includes the unknown_error
            $calls = $this->http_client->calls;

            // Find the batch call (there might be multiple calls)
            $batchCall = null;
            foreach ($calls as $call) {
                if ($call['path'] === '/batch/') {
                    $batchCall = $call;
                    break;
                }
            }

            $this->assertNotNull($batchCall, "Expected to find a /batch/ call");

            $payload = json_decode($batchCall['payload'], true);
            $event = $payload['batch'][0];

            $this->assertEquals('$feature_flag_called', $event['event']);
            $this->assertEquals('simple-test', $event['properties']['$feature_flag']);
            $this->assertNull($event['properties']['$feature_flag_response']);
            $this->assertEquals(FeatureFlagError::UNKNOWN_ERROR, $event['properties']['$feature_flag_error']);
        });
    }

    public function testApiErrorMethod()
    {
        // Test the apiError static method
        $this->assertEquals('api_error_500', FeatureFlagError::apiError(500));
        $this->assertEquals('api_error_404', FeatureFlagError::apiError(404));
        $this->assertEquals('api_error_429', FeatureFlagError::apiError(429));
    }

    public function testTimeoutError()
    {
        self::executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            // Create a mocked client that simulates a timeout (responseCode=0, curlErrno=28)
            $this->http_client = new MockedHttpClient(
                "app.posthog.com",
                flagsEndpointResponse: MockedResponses::FLAGS_RESPONSE,
                flagsEndpointResponseCode: 0,
                flagsEndpointCurlErrno: 28  // CURLE_OPERATION_TIMEDOUT
            );

            $this->client = new Client(
                self::FAKE_API_KEY,
                ["debug" => true],
                $this->http_client,
                null
            );
            PostHog::init(null, null, $this->client);

            global $errorMessages;
            $errorMessages = [];

            $result = PostHog::getFeatureFlag('simple-test', 'user-id');
            $this->assertNull($result);

            PostHog::flush();

            $calls = $this->http_client->calls;
            $batchCall = null;
            foreach ($calls as $call) {
                if ($call['path'] === '/batch/') {
                    $batchCall = $call;
                    break;
                }
            }

            $this->assertNotNull($batchCall, "Expected to find a /batch/ call");

            $payload = json_decode($batchCall['payload'], true);
            $event = $payload['batch'][0];

            $this->assertEquals('$feature_flag_called', $event['event']);
            $this->assertEquals(FeatureFlagError::TIMEOUT, $event['properties']['$feature_flag_error']);
        });
    }

    public function testConnectionError()
    {
        self::executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            // Create a mocked client that simulates a connection error (responseCode=0, curlErrno=6)
            $this->http_client = new MockedHttpClient(
                "app.posthog.com",
                flagsEndpointResponse: MockedResponses::FLAGS_RESPONSE,
                flagsEndpointResponseCode: 0,
                flagsEndpointCurlErrno: 6  // CURLE_COULDNT_RESOLVE_HOST
            );

            $this->client = new Client(
                self::FAKE_API_KEY,
                ["debug" => true],
                $this->http_client,
                null
            );
            PostHog::init(null, null, $this->client);

            global $errorMessages;
            $errorMessages = [];

            $result = PostHog::getFeatureFlag('simple-test', 'user-id');
            $this->assertNull($result);

            PostHog::flush();

            $calls = $this->http_client->calls;
            $batchCall = null;
            foreach ($calls as $call) {
                if ($call['path'] === '/batch/') {
                    $batchCall = $call;
                    break;
                }
            }

            $this->assertNotNull($batchCall, "Expected to find a /batch/ call");

            $payload = json_decode($batchCall['payload'], true);
            $event = $payload['batch'][0];

            $this->assertEquals('$feature_flag_called', $event['event']);
            $this->assertEquals(FeatureFlagError::CONNECTION_ERROR, $event['properties']['$feature_flag_error']);
        });
    }

    public function testApiError500()
    {
        self::executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            // Create a mocked client that simulates a 500 error
            $this->http_client = new MockedHttpClient(
                "app.posthog.com",
                flagsEndpointResponse: MockedResponses::FLAGS_RESPONSE,
                flagsEndpointResponseCode: 500
            );

            $this->client = new Client(
                self::FAKE_API_KEY,
                ["debug" => true],
                $this->http_client,
                null
            );
            PostHog::init(null, null, $this->client);

            global $errorMessages;
            $errorMessages = [];

            $result = PostHog::getFeatureFlag('simple-test', 'user-id');
            $this->assertNull($result);

            PostHog::flush();

            $calls = $this->http_client->calls;
            $batchCall = null;
            foreach ($calls as $call) {
                if ($call['path'] === '/batch/') {
                    $batchCall = $call;
                    break;
                }
            }

            $this->assertNotNull($batchCall, "Expected to find a /batch/ call");

            $payload = json_decode($batchCall['payload'], true);
            $event = $payload['batch'][0];

            $this->assertEquals('$feature_flag_called', $event['event']);
            $this->assertEquals('api_error_500', $event['properties']['$feature_flag_error']);
        });
    }

    public function testQuotaLimitedError()
    {
        self::executeAtFrozenDateTime(new \DateTime('2022-05-01'), function () {
            // Create a response with quotaLimited containing feature_flags
            $quotaLimitedResponse = array_merge(MockedResponses::FLAGS_RESPONSE, [
                'quotaLimited' => ['feature_flags']
            ]);

            $this->http_client = new MockedHttpClient(
                "app.posthog.com",
                flagsEndpointResponse: $quotaLimitedResponse
            );

            $this->client = new Client(
                self::FAKE_API_KEY,
                ["debug" => true],
                $this->http_client,
                null
            );
            PostHog::init(null, null, $this->client);

            global $errorMessages;
            $errorMessages = [];

            $result = PostHog::getFeatureFlag('simple-test', 'user-id');
            $this->assertNull($result);

            PostHog::flush();

            $calls = $this->http_client->calls;
            $batchCall = null;
            foreach ($calls as $call) {
                if ($call['path'] === '/batch/') {
                    $batchCall = $call;
                    break;
                }
            }

            $this->assertNotNull($batchCall, "Expected to find a /batch/ call");

            $payload = json_decode($batchCall['payload'], true);
            $event = $payload['batch'][0];

            $this->assertEquals('$feature_flag_called', $event['event']);
            $this->assertEquals(FeatureFlagError::QUOTA_LIMITED, $event['properties']['$feature_flag_error']);
        });
    }
}
