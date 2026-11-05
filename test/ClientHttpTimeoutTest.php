<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\Consumer;
use PostHog\HttpClient;
use ReflectionProperty;

class ClientHttpTimeoutTest extends TestCase
{
    public static function timeoutOptions(): array
    {
        return [
            'default HTTP timeout' => [[], 10000, null],
            'legacy cURL timeout' => [['timeout' => 1250], 1250, null],
            'socket seconds stay separate' => [['consumer' => 'socket', 'timeout' => 0.25], 10000, 0.25],
            'explicit HTTP timeout with socket' => [[
                'consumer' => 'socket', 'timeout' => 0.25, 'http_timeout_ms' => 1250,
            ], 1250, 0.25],
            'explicit unlimited HTTP timeout' => [[
                'consumer' => 'socket', 'timeout' => 0.25, 'http_timeout_ms' => 0,
            ], 0, 0.25],
            'positive fractional HTTP timeout rounds up' => [[
                'consumer' => 'socket', 'timeout' => 0.25, 'http_timeout_ms' => 0.5,
            ], 1, 0.25],
            'HTTP override does not change cURL delivery' => [[
                'timeout' => 2500, 'http_timeout_ms' => 1250,
            ], 1250, null],
        ];
    }

    #[DataProvider('timeoutOptions')]
    public function testTimeoutsAreIndependent(array $options, int $expected, ?float $socketTimeout): void
    {
        $client = new Client('test-key', $options, loadFeatureFlags: false);
        $http = (new ReflectionProperty(Client::class, 'httpClient'))->getValue($client);
        $timeout = new ReflectionProperty(HttpClient::class, 'curlTimeoutMilliseconds');
        self::assertSame($expected, $timeout->getValue($http));
        $consumer = (new ReflectionProperty(Client::class, 'consumer'))->getValue($client);
        if ($socketTimeout !== null) {
            self::assertInstanceOf(Consumer\Socket::class, $consumer);
            $consumerOptions = (new ReflectionProperty(Consumer::class, 'options'))->getValue($consumer);
            self::assertSame($socketTimeout, $consumerOptions['timeout']);
        } else {
            $consumerHttp = (new ReflectionProperty(Consumer\LibCurl::class, 'httpClient'))->getValue($consumer);
            self::assertSame(
                $options['timeout'] ?? 10000,
                $timeout->getValue($consumerHttp)
            );
        }
    }
}
