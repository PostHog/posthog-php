<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\Test\MockErrorHandler;

/**
 * Test suite for the 32KB payload size limit fix.
 *
 * This addresses the critical data loss issue where events exceeding 32KB
 * when batched together were silently dropped instead of being split and sent.
 */
class PayloadSizeLimitFixTest extends TestCase
{
    private $client;
    private $mockHttpClient;

    public function setUp(): void
    {
        date_default_timezone_set("UTC");

        // Create a mock HTTP client that tracks successful requests
        $this->mockHttpClient = new MockedHttpClient(
            "app.posthog.com",
            true,
            10000,
            false,
            true
        );

        $this->client = new Client(
            "test_api_key",
            [
                "consumer" => "lib_curl",
                "debug" => true,
                "batch_size" => 10, // Small batch size to control test
            ],
            $this->mockHttpClient
        );
    }

    /**
     * Helper method to reset and count HTTP requests
     */
    private function resetRequestCount(): void
    {
        $this->mockHttpClient->calls = [];
    }

    /**
     * Helper method to get number of batch requests made
     */
    private function getBatchRequestCount(): int
    {
        if (!isset($this->mockHttpClient->calls)) {
            return 0;
        }

        $batchRequests = 0;
        foreach ($this->mockHttpClient->calls as $call) {
            if ($call['path'] === '/batch/') {
                $batchRequests++;
            }
        }
        return $batchRequests;
    }

    /**
     * Test that the fix properly handles oversized batches by splitting them
     */
    public function testOversizedBatchSplitting(): void
    {
        // Reset request counter
        $this->resetRequestCount();

        // Create events with large properties to exceed 32KB when batched
        $largeProperty = str_repeat('A', 4000); // 4KB string

        // Create 8 events, each ~4KB, totaling ~32KB+ (exceeds 32KB limit)
        for ($i = 0; $i < 8; $i++) {
            $result = $this->client->capture([
                "distinctId" => "user-{$i}",
                "event" => "large_event_{$i}",
                "properties" => [
                    "large_data" => $largeProperty,
                    "event_index" => $i
                ]
            ]);
            $this->assertTrue($result, "Event {$i} should be captured successfully");
        }

        // Flush remaining events
        $flushResult = $this->client->flush();
        $this->assertTrue($flushResult, "Flush should succeed with splitting");

        // Verify that multiple HTTP requests were made due to splitting
        $requestCount = $this->getBatchRequestCount();
        $this->assertGreaterThan(1, $requestCount, "Multiple requests should be made when batch is split");
    }

    /**
     * Test that single oversized messages are properly handled and reported
     */
    public function testSingleOversizedMessage(): void
    {
        // Create a single event that exceeds 32KB
        $veryLargeProperty = str_repeat('X', 33 * 1024); // 33KB string

        // Capture error logs
        $errorHandler = new MockErrorHandler();
        $client = new Client(
            "test_api_key",
            [
                "consumer" => "lib_curl",
                "debug" => true,
                "error_handler" => [$errorHandler, 'handleError']
            ],
            $this->mockHttpClient
        );

        $result = $client->capture([
            "distinctId" => "oversized_user",
            "event" => "oversized_event",
            "properties" => [
                "very_large_data" => $veryLargeProperty
            ]
        ]);

        // The event should still be accepted initially
        $this->assertTrue($result, "Oversized event should be accepted initially");

        // But flush should fail and error should be logged
        $flushResult = $client->flush();
        $this->assertFalse($flushResult, "Flush should fail for oversized single message");

        // Verify error was reported
        $this->assertTrue(
            $errorHandler->hasError('payload_too_large'),
            "Error should be reported for oversized message"
        );
    }

    /**
     * Test that multiple small events that accumulate to exceed 32KB are handled properly
     */
    public function testAccumulativePayloadSizeHandling(): void
    {
        $this->resetRequestCount();

        // Each event is small (2KB) but 20 events = 40KB total
        $smallProperty = str_repeat('Z', 2000);

        $allSuccessful = true;
        for ($i = 0; $i < 20; $i++) {
            $result = $this->client->capture([
                "distinctId" => "accumulative_user_{$i}",
                "event" => "small_event",
                "properties" => [
                    "data" => $smallProperty,
                    "index" => $i
                ]
            ]);
            if (!$result) {
                $allSuccessful = false;
            }
        }

        $this->assertTrue($allSuccessful, "All small events should be accepted");

        // Final flush should succeed because batches were split appropriately
        $flushResult = $this->client->flush();
        $this->assertTrue($flushResult, "Final flush should succeed with proper batch splitting");

        // Verify multiple requests were made
        $requestCount = $this->getBatchRequestCount();
        $this->assertGreaterThan(
            1,
            $requestCount,
            "Multiple requests should be made for accumulative payload"
        );
    }

    /**
     * Test that normal-sized batches still work correctly
     */
    public function testNormalSizedBatches(): void
    {
        $this->resetRequestCount();

        // Create normal-sized events
        for ($i = 0; $i < 5; $i++) {
            $result = $this->client->capture([
                "distinctId" => "normal_user_{$i}",
                "event" => "normal_event",
                "properties" => [
                    "small_data" => "normal data",
                    "index" => $i
                ]
            ]);
            $this->assertTrue($result, "Normal event {$i} should be captured");
        }

        $flushResult = $this->client->flush();
        $this->assertTrue($flushResult, "Normal batch flush should succeed");

        // Should only need one request for normal sized batch
        $requestCount = $this->getBatchRequestCount();
        $this->assertEquals(1, $requestCount, "Only one request should be made for normal batch");
    }
}
