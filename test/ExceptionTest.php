<?php

namespace PostHog\Test;

require_once 'test/error_log_mock.php';

use Error;
use Exception;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\ExceptionUtils;
use PostHog\PostHog;

class ExceptionTest extends TestCase
{
    use ClockMockTrait;

    const FAKE_API_KEY = "random_key";

    private MockedHttpClient $http_client;
    private Client $client;

    public function setUp(): void
    {
        date_default_timezone_set("UTC");
        $this->http_client = new MockedHttpClient("app.posthog.com");
        // Don't pass personalAPIKey to avoid loadFlags() call in constructor
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client,
        );
        PostHog::init(null, null, $this->client);

        global $errorMessages;
        $errorMessages = [];
    }

    /**
     * Helper to get the batch payload from the last HTTP call.
     */
    private function getLastBatchPayload(): array
    {
        $lastCall = end($this->http_client->calls);
        return json_decode($lastCall["payload"], true);
    }

    public function testBasicCaptureException(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2024-01-01'), function () {
            $exception = new Exception("Something went wrong");

            $result = $this->client->captureException($exception, "user-123");
            $this->assertTrue($result);

            $this->client->flush();

            $payload = $this->getLastBatchPayload();
            $batch = $payload["batch"];
            $this->assertCount(1, $batch);

            $event = $batch[0];
            $this->assertEquals('$exception', $event["event"]);
            $this->assertEquals('user-123', $event["distinct_id"]);

            $exceptionList = $event["properties"]['$exception_list'];
            $this->assertCount(1, $exceptionList);

            $entry = $exceptionList[0];
            $this->assertEquals('Exception', $entry["type"]);
            $this->assertEquals("Something went wrong", $entry["value"]);
            $this->assertEquals('generic', $entry["mechanism"]["type"]);
            $this->assertTrue($entry["mechanism"]["handled"]);
            $this->assertArrayHasKey('frames', $entry["stacktrace"]);
            $this->assertEquals('raw', $entry["stacktrace"]["type"]);
        });
    }

    public function testChainedExceptions(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2024-01-01'), function () {
            $root = new Exception("Root cause");
            $outer = new Exception("Outer error", 0, $root);

            $this->client->captureException($outer, "user-123");
            $this->client->flush();

            $payload = $this->getLastBatchPayload();
            $exceptionList = $payload["batch"][0]["properties"]['$exception_list'];

            // Should have 2 entries: outermost first, root cause last
            $this->assertCount(2, $exceptionList);

            // Outermost exception (index 0)
            $this->assertEquals("Outer error", $exceptionList[0]["value"]);
            $this->assertEquals('generic', $exceptionList[0]["mechanism"]["type"]);
            $this->assertEquals(0, $exceptionList[0]["mechanism"]["exception_id"]);
            $this->assertArrayNotHasKey('parent_id', $exceptionList[0]["mechanism"]);

            // Root cause (index 1)
            $this->assertEquals("Root cause", $exceptionList[1]["value"]);
            $this->assertEquals('chained', $exceptionList[1]["mechanism"]["type"]);
            $this->assertEquals(1, $exceptionList[1]["mechanism"]["exception_id"]);
            $this->assertEquals(0, $exceptionList[1]["mechanism"]["parent_id"]);
            $this->assertEquals('getPrevious()', $exceptionList[1]["mechanism"]["source"]);
        });
    }

    public function testAnonymousDistinctId(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2024-01-01'), function () {
            $exception = new Exception("Anonymous error");

            $this->client->captureException($exception);
            $this->client->flush();

            $payload = $this->getLastBatchPayload();
            $event = $payload["batch"][0];

            // Should have a non-empty distinct_id (UUID)
            $this->assertNotEmpty($event["distinct_id"]);
            // Should match UUID v4 format
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $event["distinct_id"]
            );
            // Should disable person processing
            $this->assertFalse($event["properties"]['$process_person_profile']);
        });
    }

    public function testHandledFalse(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2024-01-01'), function () {
            $exception = new Exception("Unhandled error");

            $this->client->captureException($exception, "user-123", [], false);
            $this->client->flush();

            $payload = $this->getLastBatchPayload();
            $exceptionList = $payload["batch"][0]["properties"]['$exception_list'];

            $this->assertFalse($exceptionList[0]["mechanism"]["handled"]);
        });
    }

    public function testFrameOrderingOldestFirst(): void
    {
        // Create an exception with a real stack trace
        $exception = $this->createNestedExceptionForTest();

        $exceptionList = ExceptionUtils::buildExceptionList($exception);
        $frames = $exceptionList[0]["stacktrace"]["frames"];

        $this->assertNotEmpty($frames);

        // The last frame should be the throw location (this test file)
        $lastFrame = end($frames);
        $this->assertStringContainsString('ExceptionTest.php', $lastFrame["filename"]);
    }

    public function testInAppDetection(): void
    {
        // App path should be in_app
        $this->assertTrue(ExceptionUtils::isInApp('/app/src/MyClass.php'));

        // Vendor path should NOT be in_app
        $this->assertFalse(ExceptionUtils::isInApp('/app/vendor/some/package/File.php'));

        // Internal should NOT be in_app
        $this->assertFalse(ExceptionUtils::isInApp('[internal]'));
    }

    public function testSourceContextDisabled(): void
    {
        $exception = new Exception("No context");

        $exceptionList = ExceptionUtils::buildExceptionList($exception, 0);
        $frames = $exceptionList[0]["stacktrace"]["frames"];

        foreach ($frames as $frame) {
            $this->assertArrayNotHasKey('context_line', $frame);
            $this->assertArrayNotHasKey('pre_context', $frame);
            $this->assertArrayNotHasKey('post_context', $frame);
        }
    }

    public function testSourceContextPresent(): void
    {
        // Create exception that references this file (which is readable)
        $exception = new Exception("Context test");

        $exceptionList = ExceptionUtils::buildExceptionList($exception, 3);
        $frames = $exceptionList[0]["stacktrace"]["frames"];

        // Find the frame for this file (the throw location, should be last)
        $thisFileFrame = null;
        foreach ($frames as $frame) {
            if (str_contains($frame['filename'], 'ExceptionTest.php')) {
                $thisFileFrame = $frame;
                break;
            }
        }

        $this->assertNotNull($thisFileFrame, 'Should find a frame from this test file');
        $this->assertArrayHasKey('context_line', $thisFileFrame);
        $this->assertArrayHasKey('pre_context', $thisFileFrame);
        $this->assertArrayHasKey('post_context', $thisFileFrame);
        $this->assertNotEmpty($thisFileFrame['context_line']);
        $this->assertIsArray($thisFileFrame['pre_context']);
        $this->assertIsArray($thisFileFrame['post_context']);
    }

    public function testSourceContextLinesOption(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2024-01-01'), function () {
            $httpClient = new MockedHttpClient("app.posthog.com");
            $client = new Client(
                self::FAKE_API_KEY,
                [
                    "debug" => true,
                    "exception_source_context_lines" => 0,
                ],
                $httpClient,
            );

            $exception = new Exception("No context from option");
            $client->captureException($exception, "user-123");
            $client->flush();

            $lastCall = end($httpClient->calls);
            $payload = json_decode($lastCall["payload"], true);
            $frames = $payload["batch"][0]["properties"]['$exception_list'][0]["stacktrace"]["frames"];

            foreach ($frames as $frame) {
                $this->assertArrayNotHasKey('context_line', $frame);
                $this->assertArrayNotHasKey('pre_context', $frame);
                $this->assertArrayNotHasKey('post_context', $frame);
            }
        });
    }

    public function testPhpError(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2024-01-01'), function () {
            // PHP \Error is also a Throwable
            $error = new Error("Type error occurred");

            $result = $this->client->captureException($error, "user-123");
            $this->assertTrue($result);

            $this->client->flush();

            $payload = $this->getLastBatchPayload();
            $exceptionList = $payload["batch"][0]["properties"]['$exception_list'];

            $this->assertCount(1, $exceptionList);
            $this->assertEquals('Error', $exceptionList[0]["type"]);
            $this->assertEquals("Type error occurred", $exceptionList[0]["value"]);
        });
    }

    public function testStaticFacade(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2024-01-01'), function () {
            $exception = new Exception("Facade test");

            $result = PostHog::captureException($exception, "user-456");
            $this->assertTrue($result);

            PostHog::flush();

            $payload = $this->getLastBatchPayload();
            $event = $payload["batch"][0];

            $this->assertEquals('$exception', $event["event"]);
            $this->assertEquals('user-456', $event["distinct_id"]);
            $this->assertEquals('Exception', $event["properties"]['$exception_list'][0]["type"]);
        });
    }

    public function testAdditionalProperties(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2024-01-01'), function () {
            $exception = new Exception("With props");

            $this->client->captureException($exception, "user-123", [
                'environment' => 'production',
                'release' => 'v1.2.3',
            ]);
            $this->client->flush();

            $payload = $this->getLastBatchPayload();
            $props = $payload["batch"][0]["properties"];

            $this->assertEquals('production', $props['environment']);
            $this->assertEquals('v1.2.3', $props['release']);
            $this->assertArrayHasKey('$exception_list', $props);
        });
    }

    public function testFrameFunction(): void
    {
        // Test building a frame with class and instance method
        $frame = ExceptionUtils::buildFrame([
            'file' => '/app/src/MyClass.php',
            'line' => 42,
            'class' => 'App\\MyClass',
            'type' => '->',
            'function' => 'doSomething',
        ], 0);

        $this->assertEquals('App\\MyClass->doSomething', $frame['function']);
        $this->assertEquals('/app/src/MyClass.php', $frame['filename']);
        $this->assertEquals(42, $frame['lineno']);
        $this->assertEquals('php', $frame['platform']);
        $this->assertTrue($frame['in_app']);
    }

    public function testFrameStaticMethod(): void
    {
        $frame = ExceptionUtils::buildFrame([
            'file' => '/app/src/MyClass.php',
            'line' => 10,
            'class' => 'App\\MyClass',
            'type' => '::',
            'function' => 'staticMethod',
        ], 0);

        $this->assertEquals('App\\MyClass::staticMethod', $frame['function']);
    }

    public function testFrameInternalFunction(): void
    {
        // Internal PHP functions have no file/line
        $frame = ExceptionUtils::buildFrame([
            'function' => 'array_map',
        ], 0);

        $this->assertEquals('[internal]', $frame['filename']);
        $this->assertEquals(0, $frame['lineno']);
        $this->assertEquals('array_map', $frame['function']);
        $this->assertFalse($frame['in_app']);
    }

    public function testFrameVendorPath(): void
    {
        $frame = ExceptionUtils::buildFrame([
            'file' => '/app/vendor/guzzle/guzzle/src/Client.php',
            'line' => 100,
            'class' => 'GuzzleHttp\\Client',
            'type' => '->',
            'function' => 'send',
        ], 0);

        $this->assertFalse($frame['in_app']);
    }

    public function testThreeDeepChain(): void
    {
        $root = new Exception("Database error");
        $middle = new Exception("Repository failed", 0, $root);
        $outer = new Exception("Controller error", 0, $middle);

        $exceptionList = ExceptionUtils::buildExceptionList($outer);

        $this->assertCount(3, $exceptionList);

        // Outermost first
        $this->assertEquals("Controller error", $exceptionList[0]["value"]);
        $this->assertEquals(0, $exceptionList[0]["mechanism"]["exception_id"]);
        $this->assertArrayNotHasKey('parent_id', $exceptionList[0]["mechanism"]);

        // Middle
        $this->assertEquals("Repository failed", $exceptionList[1]["value"]);
        $this->assertEquals(1, $exceptionList[1]["mechanism"]["exception_id"]);
        $this->assertEquals(0, $exceptionList[1]["mechanism"]["parent_id"]);

        // Root cause last
        $this->assertEquals("Database error", $exceptionList[2]["value"]);
        $this->assertEquals(2, $exceptionList[2]["mechanism"]["exception_id"]);
        $this->assertEquals(1, $exceptionList[2]["mechanism"]["parent_id"]);
    }

    public function testLibraryProperties(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2024-01-01'), function () {
            $exception = new Exception("Test");
            $this->client->captureException($exception, "user-123");
            $this->client->flush();

            $payload = $this->getLastBatchPayload();
            $event = $payload["batch"][0];

            // Standard PostHog library properties should be present
            $this->assertEquals('posthog-php', $event["properties"]['$lib']);
            $this->assertArrayHasKey('$lib_version', $event["properties"]);
        });
    }

    public function testHandledTrueByDefault(): void
    {
        $exception = new Exception("Handled by default");
        $exceptionList = ExceptionUtils::buildExceptionList($exception);

        // Default mechanism.handled should be true
        $this->assertTrue($exceptionList[0]["mechanism"]["handled"]);
    }

    public function testChainedHandledFlagOnOutermost(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2024-01-01'), function () {
            $root = new Exception("Root");
            $outer = new Exception("Outer", 0, $root);

            $this->client->captureException($outer, "user-123", [], false);
            $this->client->flush();

            $payload = $this->getLastBatchPayload();
            $exceptionList = $payload["batch"][0]["properties"]['$exception_list'];

            // handled=false should only be on the outermost exception
            $this->assertFalse($exceptionList[0]["mechanism"]["handled"]);
            // Inner exceptions keep default handled=true
            $this->assertTrue($exceptionList[1]["mechanism"]["handled"]);
        });
    }

    /**
     * Helper to create an exception with a deeper call stack for frame ordering tests.
     */
    private function createNestedExceptionForTest(): Exception
    {
        return $this->helperLevel1();
    }

    private function helperLevel1(): Exception
    {
        return $this->helperLevel2();
    }

    private function helperLevel2(): Exception
    {
        return new Exception("Nested test");
    }
}
