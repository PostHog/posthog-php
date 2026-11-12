<?php

// phpcs:disable PSR1.Files.SideEffects
namespace PostHog\Test;

require_once 'test/error_log_mock.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use PostHog\Client;

class FailedFlagTelemetryTest extends TestCase
{
    public static function failedReads(): iterable
    {
        foreach ([true, false, null] as $globalError) {
            foreach ([false, true] as $localFallback) {
                foreach ([null, 'placeholder'] as $variant) {
                    yield [$globalError, $localFallback, $variant];
                }
            }
        }
    }

    #[DataProvider('failedReads')]
    #[IgnoreDeprecations]
    public function testFailedReadKeepsBothErrorCausesAndNullResponse(
        ?bool $globalError,
        bool $localFallback,
        ?string $variant
    ): void {
        global $errorMessages;
        $errorMessages = [];
        $response = [
            'requestId' => 'request',
            'featureFlags' => ['checkout' => 'placeholder'],
            'featureFlagPayloads' => ['checkout' => '"placeholder"'],
            'flags' => [
                'checkout' => [
                    'key' => 'checkout',
                    'enabled' => false,
                    'variant' => $variant,
                    'failed' => true,
                    'metadata' => ['payload' => '{"copy":"remote"}'],
                ],
                'healthy' => [
                    'key' => 'healthy',
                    'enabled' => false,
                    'variant' => null,
                    'failed' => false,
                    'metadata' => ['id' => 2, 'version' => 1],
                    'reason' => ['description' => 'No match'],
                ],
            ],
        ];
        if ($globalError !== null) {
            $response['errorsWhileComputingFlags'] = $globalError;
        }
        $http = new MockedHttpClient(
            'app.posthog.com',
            flagEndpointResponse: ['flags' => [[
                'key' => 'checkout',
                'active' => true,
                'ensure_experience_continuity' => true,
                'filters' => ['groups' => [['properties' => [], 'rollout_percentage' => 100]]],
            ]]],
            flagsEndpointResponse: $response
        );
        $client = new Client('key', ['debug' => true], $http, $localFallback ? 'secret' : null);

        $this->assertNull($client->getFeatureFlagResult('checkout', 'person'));
        $this->assertNull($client->getFeatureFlag('checkout', 'person'));
        $this->assertNull($client->isFeatureEnabled('checkout', 'person'));
        $this->assertNull($client->getFeatureFlagPayload('checkout', 'person'));
        $client->flush();

        $events = $this->events($http);
        $this->assertCount(1, $events);
        $properties = $events[0]['properties'];
        $this->assertNull($properties['$feature_flag_response']);
        $this->assertSame(
            $globalError === true ? 'errors_while_computing_flags,evaluation_failed' : 'evaluation_failed',
            $properties['$feature_flag_error']
        );
        $this->assertSame('request', $properties['$feature_flag_request_id']);

        $this->assertNull($client->getFeatureFlagResult('checkout', 'suppressed', sendFeatureFlagEvents: false));
        $client->flush();
        $this->assertCount(1, $this->events($http));

        $healthy = $client->getFeatureFlagResult('healthy', 'person');
        $this->assertNotNull($healthy);
        $this->assertFalse($healthy->getValue());
        $client->flush();
        $events = $this->events($http);
        $this->assertCount(2, $events);
        $this->assertSame(
            $globalError === true ? 'errors_while_computing_flags' : null,
            $events[1]['properties']['$feature_flag_error'] ?? null
        );
    }

    private function events(MockedHttpClient $http): array
    {
        $events = [];
        foreach ($http->calls ?? [] as $call) {
            if (str_starts_with($call['path'], '/batch/')) {
                foreach (json_decode($call['payload'], true)['batch'] as $event) {
                    $events[] = $event;
                }
            }
        }
        return $events;
    }
}
