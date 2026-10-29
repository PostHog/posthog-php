<?php

namespace Tests\Feature;

use App\Support\RedactDemoProperties;
use PHPUnit\Framework\Attributes\DataProvider;
use PostHog\Client;
use PostHog\HttpClient;
use PostHog\HttpResponse;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHog as Manager;
use Tests\TestCase;

final class TransportTest extends TestCase
{
    public function testRealSdkSerializesFlagAttachmentAndExposuresUsingMockTransport(): void
    {
        $requests = [];
        $http = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(function ($path, $payload) use (&$requests): HttpResponse {
            $requests[] = ['path' => $path, 'body' => json_decode($payload, true, flags: JSON_THROW_ON_ERROR)];
            return new HttpResponse($path === '/flags/?v=2' ? json_encode([
                'flags' => [
                    'demo-checkout' => ['enabled' => true, 'variant' => null],
                    'demo-layout' => ['enabled' => true, 'variant' => 'compact'],
                ],
                'featureFlagPayloads' => ['demo-layout' => '{"color":"blue"}'],
            ]) : '{}', 200);
        });
        $this->useClient($http);
        $this->post('/demo/flags')->assertRedirect('/')->assertSessionHas('result.variant', 'compact');
        $flagRequests = array_values(array_filter($requests, fn ($request) => $request['path'] === '/flags/?v=2'));
        self::assertCount(1, $flagRequests);
        self::assertTrue($flagRequests[0]['body']['geoip_disable']);
        $events = array_merge(...array_map(
            fn ($request) => $request['body']['batch'] ?? [],
            $requests,
        ));
        $decision = array_values(array_filter($events, fn ($event) => $event['event'] === 'demo_flag_decision'))[0];
        self::assertSame('demo-visitor', $decision['distinct_id']);
        self::assertSame('compact', $decision['properties']['$feature/demo-layout']);
        self::assertSame(['demo-checkout', 'demo-layout'], $decision['properties']['$active_feature_flags']);
        self::assertCount(2, array_filter($events, fn ($event) => $event['event'] === '$feature_flag_called'));
        PostHog::shutdown();
    }

    public static function pennantModes(): array
    {
        return ['local only' => [true], 'remote allowed' => [false]];
    }

    #[DataProvider('pennantModes')]
    public function testPennantDemoHonorsLocalOnlyMode(bool $localOnly): void
    {
        config(['demo.local_only' => $localOnly]);
        $requests = [];
        $http = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(function ($path) use (&$requests): HttpResponse {
            $requests[] = $path;
            return new HttpResponse($path === '/flags/?v=2' ? json_encode(['flags' => [
                'demo-checkout' => ['enabled' => true, 'variant' => null],
                'demo-layout' => ['enabled' => true, 'variant' => 'compact'],
            ]]) : '{}', 200);
        });
        $this->useClient($http);
        $response = $this->post('/demo/pennant')->assertRedirect('/');
        self::assertCount($localOnly ? 0 : 1, array_filter($requests, fn ($path) => $path === '/flags/?v=2'));
        if ($localOnly) {
            $response->assertSessionHas('result.skipped', true)
                ->assertSessionHas('result.reason', 'Use the native flags form for local-only evaluation.');
            self::assertSame([], $requests);
        } else {
            $response->assertSessionHas('result.enabled', true)->assertSessionHas('result.variant', 'compact');
        }
        PostHog::shutdown();
    }

    public function testFlagsAndTransportFailureDoNotBreakDemoResponse(): void
    {
        $paths = [];
        $outage = true;
        $http = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(function ($path) use (&$paths, &$outage): HttpResponse {
            $paths[] = $path;
            return new HttpResponse('{"error":"synthetic outage"}', $outage ? 503 : 200);
        });
        $this->useClient($http);
        $this->post('/demo/flags')->assertRedirect('/')->assertSessionHas('result.keys', []);
        $this->post('/demo/flush')->assertRedirect('/')->assertSessionHas('result.flushed', false);
        self::assertContains('/flags/?v=2', $paths);
        self::assertContains('/batch/', $paths);
        $outage = false;
        self::assertTrue(PostHog::flush());
        PostHog::shutdown();
    }

    public function testBeforeSendCallbackDropsExplicitSensitiveProperties(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())->method('sendRequest')->willReturnCallback(function (
            $path,
            $payload,
        ): HttpResponse {
            self::assertSame('/batch/', $path);
            $properties = json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['batch'][0]['properties'];
            self::assertArrayNotHasKey('email', $properties);
            self::assertArrayNotHasKey('password', $properties);
            self::assertSame('demo', $properties['plan']);
            return new HttpResponse('{}', 200);
        });
        $this->useClient($http);
        PostHog::capture(
            'test_redaction',
            ['email' => 'synthetic', 'password' => 'synthetic', 'plan' => 'demo'],
            'demo',
        );
        self::assertTrue(PostHog::flush());
        PostHog::shutdown();
    }

    private function useClient(HttpClient $http): void
    {
        config(['posthog.enabled' => true, 'posthog.api_key' => 'synthetic-test-key', 'posthog.secret_key' => null]);
        $client = new Client('synthetic-test-key', [
            'consumer' => 'lib_curl',
            'disableGeoip' => true,
            'feature_flag_request_max_retries' => 0,
            'before_send' => new RedactDemoProperties(),
        ], $http, loadFeatureFlags: false);
        $manager = app(Manager::class);
        // Match the runtime's transport-injection tests without exposing an application API for test wiring.
        (new \ReflectionProperty(Manager::class, 'sdk'))->setValue($manager, $client);
        PostHog::swap($manager);
    }
}
