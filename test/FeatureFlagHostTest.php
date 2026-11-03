<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;

class FeatureFlagHostTest extends TestCase
{
    public static function hosts(): array
    {
        return [
            'URL overrides default TLS' => ['http://', '', []],
            'URL with trailing slash' => ['http://', '/', []],
            'bare host preserves TLS option' => ['', '', ['ssl' => false]],
        ];
    }

    #[DataProvider('hosts')]
    public function testDefinitionsEvaluationAndIngestionUseTheConfiguredHost(
        string $prefix,
        string $suffix,
        array $options,
    ): void {
        $server = new LocalHttpServer([
            ['body' => json_encode(['flags' => [], 'group_type_mapping' => [], 'cohorts' => []])],
            ['body' => json_encode([
                'flags' => [
                    'demo-checkout' => ['enabled' => true, 'variant' => null],
                    'demo-layout' => [
                        'enabled' => true,
                        'variant' => 'blue',
                        'metadata' => ['payload' => '{"color":"blue"}'],
                    ],
                ],
            ])],
            ['body' => '{"status":"Ok"}'],
        ]);
        $client = new Client('test-key', $options + [
            'host' => $prefix . $server->address() . $suffix,
            'consumer' => 'lib_curl',
            'feature_flag_request_max_retries' => 0,
        ], loadFeatureFlags: false, secretKey: 'test-secret');

        try {
            $client->loadFlags();
            $flags = $client->evaluateFlags('test-visitor');
            self::assertTrue($flags->isEnabled('demo-checkout'));
            self::assertSame('blue', $flags->getFlag('demo-layout'));
            self::assertSame(['color' => 'blue'], $flags->getFlagPayload('demo-layout'));
            self::assertTrue($client->capture([
                'event' => 'demo_flag_decision',
                'distinctId' => 'test-visitor',
                'flags' => $flags->onlyAccessed(),
            ]));
            self::assertTrue($client->flush());

            $requests = $server->requests();
            self::assertCount(3, $requests);
            self::assertSame(
                'GET /flags/definitions?send_cohorts&token=test-key HTTP/1.1',
                $requests[0]['requestLine'],
            );
            self::assertSame('Bearer test-secret', $requests[0]['headers']['authorization']);
            self::assertSame('POST /flags/?v=2 HTTP/1.1', $requests[1]['requestLine']);
            $evaluation = json_decode($requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('test-key', $evaluation['api_key']);
            self::assertSame('test-visitor', $evaluation['distinct_id']);
            self::assertSame('POST /batch/ HTTP/1.1', $requests[2]['requestLine']);
            $batch = json_decode($requests[2]['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertCount(3, $batch['batch']);
            $event = $batch['batch'][2];
            self::assertSame('demo_flag_decision', $event['event']);
            self::assertSame(true, $event['properties']['$feature/demo-checkout']);
            self::assertSame('blue', $event['properties']['$feature/demo-layout']);
        } finally {
            $client->shutdown();
            $server->stop();
        }
    }
}
