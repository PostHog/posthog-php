<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\PostHog;
use PostHog\Test\Assets\MockedResponses;
use ReflectionProperty;
use RuntimeException;

class GeoipTest extends TestCase
{
    public function tearDown(): void
    {
        (new ReflectionProperty(PostHog::class, 'client'))->setValue(null, null);
    }

    public static function geoipCases(): array
    {
        return [
            'default preserves compatibility' => [[], [], false],
            'client enabled' => [['disableGeoip' => false], [], false],
            'client disabled' => [['disableGeoip' => true], [], true],
            'call disables over default' => [[], ['disableGeoip' => true], true],
            'call enables over client' => [['disableGeoip' => true], ['disableGeoip' => false], false],
            'call disables over client' => [['disableGeoip' => false], ['disableGeoip' => true], true],
            'null inherits client' => [['disableGeoip' => false], ['disableGeoip' => null], false],
        ];
    }

    #[DataProvider('geoipCases')]
    public function testEventOptions(array $clientOptions, array $callOptions, bool $disabled): void
    {
        $http = new MockedHttpClient('app.posthog.com');
        $client = new Client('test-key', $clientOptions, $http, null, false);
        PostHog::init(client: $client);

        $messages = [
            'capture' => ['event' => 'test', 'distinctId' => 'user'],
            'identify' => ['distinctId' => 'user', 'properties' => ['plan' => 'pro']],
            'alias' => ['distinctId' => 'user', 'alias' => 'other-user'],
            'groupIdentify' => ['groupType' => 'company', 'groupKey' => 'acme', 'properties' => ['plan' => 'pro']],
        ];
        foreach ($messages as $method => $message) {
            $this->assertTrue(PostHog::$method(array_merge($message, $callOptions)));
        }
        $client->flush();

        $events = json_decode($http->calls[0]['payload'], true)['batch'];
        $this->assertCount(4, $events);
        foreach ($events as $event) {
            $this->assertArrayNotHasKey('disableGeoip', $event);
            if ($disabled) {
                $this->assertTrue($event['properties']['$geoip_disable']);
            } else {
                $this->assertArrayNotHasKey('$geoip_disable', $event['properties']);
            }
        }
        $this->assertSame(['plan' => 'pro'], $events[1]['$set']);
        $this->assertSame(['plan' => 'pro'], $events[3]['properties']['$group_set']);
    }

    #[DataProvider('geoipCases')]
    public function testFlagOptions(array $clientOptions, array $callOptions, bool $disabled): void
    {
        $http = new MockedHttpClient('app.posthog.com', flagsEndpointResponse: MockedResponses::FLAGS_V2_RESPONSE);
        $client = new Client('test-key', $clientOptions, $http, null, false);
        PostHog::init(client: $client);

        PostHog::evaluateFlags('user', ...$callOptions)->only(['simple-test'])->isEnabled('simple-test');
        $client->flags('user', ...$callOptions);
        $client->flush();

        $this->assertCount(3, $http->calls);
        foreach (array_slice($http->calls, 0, 2) as $call) {
            $this->assertSame($disabled, json_decode($call['payload'], true)['geoip_disable']);
        }
        $event = json_decode($http->calls[2]['payload'], true)['batch'][0];
        $this->assertSame('$feature_flag_called', $event['event']);
        if ($disabled) {
            $this->assertTrue($event['properties']['$geoip_disable']);
        } else {
            $this->assertArrayNotHasKey('$geoip_disable', $event['properties']);
        }
    }

    public function testBeforeSendCanOverrideClientSettingAndExceptionInheritsIt(): void
    {
        $http = new MockedHttpClient('app.posthog.com');
        $beforeSendEvent = null;
        $client = new Client('test-key', [
            'disableGeoip' => true,
            'before_send' => function (array $event) use (&$beforeSendEvent): array {
                $beforeSendEvent = $event;
                $event['properties']['$geoip_disable'] = false;
                return $event;
            },
        ], $http, null, false);
        $client->captureException(new RuntimeException('test'), 'user');
        $client->flush();

        $event = json_decode($http->calls[0]['payload'], true)['batch'][0];
        $this->assertTrue($beforeSendEvent['properties']['$geoip_disable']);
        $this->assertFalse($event['properties']['$geoip_disable']);
    }

    #[DataProvider('geoipCases')]
    public function testCaptureFlagEnrichmentOptions(array $clientOptions, array $callOptions, bool $disabled): void
    {
        $http = new MockedHttpClient('app.posthog.com');
        $client = new Client('test-key', $clientOptions, $http, null, false);
        $client->capture(array_merge([
            'event' => 'test',
            'distinctId' => 'user',
            'send_feature_flags' => true,
        ], $callOptions));
        $client->flush();

        $this->assertSame($disabled, json_decode($http->calls[0]['payload'], true)['geoip_disable']);
    }

    public function testMinimalExposurePreservesGeoipOption(): void
    {
        $response = MockedResponses::FLAGS_V2_RESPONSE;
        $response['minimalFlagCalledEvents'] = true;
        $response['flags']['simple-test']['metadata']['has_experiment'] = false;
        $http = new MockedHttpClient('app.posthog.com', flagsEndpointResponse: $response);
        $client = new Client('test-key', ['disableGeoip' => true], $http, null, false);
        $client->evaluateFlags('user')->isEnabled('simple-test');
        $client->flush();

        $event = json_decode($http->calls[1]['payload'], true)['batch'][0];
        $this->assertTrue($event['properties']['$geoip_disable']);
        $this->assertArrayNotHasKey('$lib_consumer', $event['properties']);
    }

    public function testLegacyFlagEvaluationInheritsClientOption(): void
    {
        $http = new MockedHttpClient('app.posthog.com');
        $client = new Client('test-key', ['disableGeoip' => true], $http, null, false);
        $client->getFeatureFlag('simpleFlag', 'user');

        $this->assertTrue(json_decode($http->calls[0]['payload'], true)['geoip_disable']);
    }

    public static function explicitPropertyCases(): array
    {
        return [
            'false option preserves true property' => [false, true, true],
            'false option preserves false property' => [false, false, false],
            'true option overrides false property' => [true, false, true],
        ];
    }

    #[DataProvider('explicitPropertyCases')]
    public function testExplicitPropertyPrecedence(bool $option, bool $property, bool $expected): void
    {
        $http = new MockedHttpClient('app.posthog.com');
        $client = new Client('test-key', [], $http, null, false);
        $client->capture([
            'event' => 'test',
            'distinctId' => 'user',
            'disableGeoip' => $option,
            'properties' => ['$geoip_disable' => $property],
        ]);
        $client->flush();

        $event = json_decode($http->calls[0]['payload'], true)['batch'][0];
        $this->assertSame($expected, $event['properties']['$geoip_disable']);
    }
}
