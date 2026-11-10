<?php

namespace PostHog\Laravel\Tests;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PostHog\Client;
use PostHog\Consumer;
use PostHog\HttpClient;
use PostHog\HttpResponse;
use PostHog\Laravel\PostHog;
use PostHog\Laravel\PostHogServiceProvider;

class EnvironmentConfigTest extends TestCase
{
    private const VARIABLES = [
        'POSTHOG_TOKEN', 'POSTHOG_API_KEY',
        'POSTHOG_TIMEOUT_MS', 'POSTHOG_SOCKET_TIMEOUT_SECONDS', 'POSTHOG_BATCH_SIZE', 'POSTHOG_MAX_QUEUE_SIZE',
        'POSTHOG_FLUSH_INTERVAL_SECONDS', 'POSTHOG_COMPRESS_REQUEST', 'POSTHOG_CONSUMER',
        'POSTHOG_FF_REQUEST_TIMEOUT_MS', 'POSTHOG_FF_REQUEST_MAX_RETRIES',
        'POSTHOG_LARAVEL_QUEUE_ENABLED', 'POSTHOG_LARAVEL_QUEUE_CONNECTION', 'POSTHOG_LARAVEL_QUEUE_NAME',
    ];

    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    private function withEnvironment(array $values, callable $callback): mixed
    {
        $previous = [];
        foreach (self::VARIABLES as $name) {
            $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
            if (isset($values[$name])) {
                $_ENV[$name] = $_SERVER[$name] = $values[$name];
                putenv($name . '=' . $values[$name]);
            }
        }
        try {
            return $callback();
        } finally {
            foreach ($previous as $name => [$env, $server, $process]) {
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
                putenv($process === false ? $name : $name . '=' . $process);
            }
        }
    }

    private function configuration(): array
    {
        return require __DIR__ . '/../config/posthog.php';
    }

    private function client(array $config): Client
    {
        $config['token'] = 'project-key';
        $this->app['config']->set('posthog', $config);
        return (new PostHog($this->app))->client();
    }

    #[DataProvider('environmentOptions')]
    public function testEnvironmentValuesBecomeTypedSdkOptions(
        string $name,
        string $value,
        string $option,
        mixed $expected,
        ?string $consumer = null,
    ): void {
        $values = [$name => $value];
        if ($consumer !== null) {
            $values['POSTHOG_CONSUMER'] = $consumer;
        }
        $config = $this->withEnvironment($values, fn () => $this->configuration());
        $this->assertArrayHasKey($option, $config['sdk']);
        $this->assertSame($expected, $config['sdk'][$option]);
    }

    public static function environmentOptions(): array
    {
        return [
            'timeout' => ['POSTHOG_TIMEOUT_MS', '2500', 'timeout', 2500.0],
            'fractional socket timeout' => ['POSTHOG_SOCKET_TIMEOUT_SECONDS', '0.25', 'timeout', 0.25, 'socket'],
            'batch size' => ['POSTHOG_BATCH_SIZE', '25', 'batch_size', 25],
            'queue size' => ['POSTHOG_MAX_QUEUE_SIZE', '500', 'max_queue_size', 500],
            'flush interval' => ['POSTHOG_FLUSH_INTERVAL_SECONDS', '1.5', 'flush_interval_seconds', 1.5],
            'immediate flush' => ['POSTHOG_FLUSH_INTERVAL_SECONDS', '0', 'flush_interval_seconds', 0.0],
            'compression enabled' => ['POSTHOG_COMPRESS_REQUEST', 'true', 'compress_request', true],
            'compression disabled' => ['POSTHOG_COMPRESS_REQUEST', 'false', 'compress_request', false],
            'consumer' => ['POSTHOG_CONSUMER', 'noop', 'consumer', 'noop'],
            'flag timeout' => ['POSTHOG_FF_REQUEST_TIMEOUT_MS', '1500', 'feature_flag_request_timeout_ms', 1500],
            'flag retries' => ['POSTHOG_FF_REQUEST_MAX_RETRIES', '2', 'feature_flag_request_max_retries', 2],
            'no flag retries' => ['POSTHOG_FF_REQUEST_MAX_RETRIES', '0', 'feature_flag_request_max_retries', 0],
        ];
    }

    public static function tokenEnvironments(): array
    {
        return [
            'POSTHOG_TOKEN' => [['POSTHOG_TOKEN' => 'phc_token'], 'phc_token'],
            'older POSTHOG_API_KEY' => [['POSTHOG_API_KEY' => 'phc_api_key'], 'phc_api_key'],
            'POSTHOG_TOKEN wins' => [['POSTHOG_TOKEN' => 'phc_token', 'POSTHOG_API_KEY' => 'phc_api_key'], 'phc_token'],
            'blank POSTHOG_TOKEN falls back' => [['POSTHOG_TOKEN' => '', 'POSTHOG_API_KEY' => 'phc_api_key'], 'phc_api_key'],
        ];
    }

    #[DataProvider('tokenEnvironments')]
    public function testProjectTokenComesFromPosthogTokenWithTheOlderNameAsFallback(array $values, string $expected): void
    {
        $config = $this->withEnvironment($values, fn () => $this->configuration());
        $this->app['config']->set('posthog', $config);

        $client = (new PostHog($this->app))->client();

        $this->assertSame($expected, (new \ReflectionProperty(Client::class, 'apiKey'))->getValue($client));
    }

    public function testConfigPublishedWithTheOlderApiKeyStillEnablesTheClient(): void
    {
        $config = $this->withEnvironment([], fn () => $this->configuration());
        unset($config['token']);
        $config['api_key'] = 'phc_published';
        $this->app['config']->set('posthog', $config);

        $manager = new PostHog($this->app);

        $this->assertTrue($manager->enabled());
        $this->assertSame('phc_published', (new \ReflectionProperty(Client::class, 'apiKey'))->getValue($manager->client()));
    }

    public function testUnsetAndEmptyVariablesKeepTheSdkDefaults(): void
    {
        foreach ([[], array_fill_keys(self::VARIABLES, '')] as $values) {
            $config = $this->withEnvironment($values, fn () => $this->configuration());
            $this->assertSame(['disableGeoip' => true], $config['sdk']);
            $config['sdk']['consumer'] = 'socket';
            $consumer = (new \ReflectionProperty(Client::class, 'consumer'))->getValue($this->client($config));
            $this->assertInstanceOf(Consumer\Socket::class, $consumer);
            $options = (new \ReflectionProperty(Consumer::class, 'options'))->getValue($consumer);
            $this->assertSame(5, $options['timeout']);
        }
    }

    public function testLaravelQueueDeliveryConfigurationIsOptInTypedAndCacheable(): void
    {
        $default = $this->withEnvironment([], fn () => $this->configuration());
        $this->assertArrayHasKey('laravel_queue', $default);
        $this->assertSame(['enabled' => false, 'connection' => null, 'name' => null], $default['laravel_queue']);
        $config = $this->withEnvironment([
            'POSTHOG_LARAVEL_QUEUE_ENABLED' => 'true',
            'POSTHOG_LARAVEL_QUEUE_CONNECTION' => 'redis',
            'POSTHOG_LARAVEL_QUEUE_NAME' => 'analytics',
        ], fn () => $this->configuration());
        $this->assertSame(['enabled' => true, 'connection' => 'redis', 'name' => 'analytics'], $config['laravel_queue']);
        $cached = eval('return ' . var_export($config, true) . ';');
        $this->withEnvironment(['POSTHOG_LARAVEL_QUEUE_ENABLED' => 'false'], function () use ($cached) {
            $this->assertFalse($this->configuration()['laravel_queue']['enabled']);
            $this->assertSame(['enabled' => true, 'connection' => 'redis', 'name' => 'analytics'], $cached['laravel_queue']);
        });
    }

    public function testTimeoutUnitsAreSelectedByConsumer(): void
    {
        foreach (['lib_curl' => 2500.0, 'fork_curl' => 2500.0, 'socket' => 0.25] as $consumer => $expected) {
            $config = $this->withEnvironment([
                'POSTHOG_CONSUMER' => $consumer,
                'POSTHOG_TIMEOUT_MS' => '2500',
                'POSTHOG_SOCKET_TIMEOUT_SECONDS' => '0.25',
            ], fn () => $this->configuration());
            $sdk = $this->client($config);
            $transport = (new \ReflectionProperty(Client::class, 'consumer'))->getValue($sdk);
            $options = (new \ReflectionProperty(Consumer::class, 'options'))->getValue($transport);
            $this->assertSame($expected, $options['timeout']);
        }
    }

    public function testSocketSecondsDoNotChangeTheHttpTimeoutMilliseconds(): void
    {
        foreach ([[null, 10000], ['2500', 2500], ['0', 0], ['0.5', 1]] as [$milliseconds, $expected]) {
            $config = $this->withEnvironment([
                'POSTHOG_CONSUMER' => 'socket',
                'POSTHOG_SOCKET_TIMEOUT_SECONDS' => '0.25',
                'POSTHOG_TIMEOUT_MS' => (string) $milliseconds,
            ], fn () => $this->configuration());
            $sdk = $this->client($config);
            $http = (new \ReflectionProperty(Client::class, 'httpClient'))->getValue($sdk);
            $this->assertSame(
                $expected,
                (new \ReflectionProperty(HttpClient::class, 'curlTimeoutMilliseconds'))->getValue($http)
            );
            $transport = (new \ReflectionProperty(Client::class, 'consumer'))->getValue($sdk);
            $options = (new \ReflectionProperty(Consumer::class, 'options'))->getValue($transport);
            $this->assertSame(0.25, $options['timeout']);
        }
    }

    public function testInactiveTransportTimeoutDoesNotOverrideEventDelivery(): void
    {
        foreach ([
            ['POSTHOG_CONSUMER' => 'socket', 'POSTHOG_TIMEOUT_MS' => '2500'],
            ['POSTHOG_CONSUMER' => 'lib_curl', 'POSTHOG_SOCKET_TIMEOUT_SECONDS' => '0.25'],
        ] as $values) {
            $config = $this->withEnvironment($values, fn () => $this->configuration());
            $this->assertArrayNotHasKey('timeout', $config['sdk']);
        }
    }

    public function testConfiguredBatchSizeReachesTheRealConsumer(): void
    {
        $config = $this->withEnvironment([
            'POSTHOG_BATCH_SIZE' => '2',
            'POSTHOG_MAX_QUEUE_SIZE' => '10',
            'POSTHOG_FLUSH_INTERVAL_SECONDS' => '60',
            'POSTHOG_COMPRESS_REQUEST' => 'false',
            'POSTHOG_FF_REQUEST_TIMEOUT_MS' => '1500',
            'POSTHOG_FF_REQUEST_MAX_RETRIES' => '0',
        ], fn () => $this->configuration());
        $client = $this->client($config);
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())->method('sendRequest')->willReturnCallback(function ($path, $payload) {
            $this->assertSame('/batch/', $path);
            $this->assertSame(['first', 'second'], array_column(json_decode($payload, true)['batch'], 'event'));
            return new HttpResponse('{}', 200);
        });
        $consumer = (new \ReflectionProperty(Client::class, 'consumer'))->getValue($client);
        $this->assertInstanceOf(Consumer\LibCurl::class, $consumer);
        (new \ReflectionProperty(Consumer\LibCurl::class, 'httpClient'))->setValue($consumer, $http);
        $this->assertSame(1500, (new \ReflectionProperty(Client::class, 'featureFlagsRequestTimeout'))->getValue($client));
        $this->assertSame(0, (new \ReflectionProperty(Client::class, 'featureFlagRequestMaxRetries'))->getValue($client));
        $client->capture(['event' => 'first', 'distinctId' => 'actor']);
        $client->capture(['event' => 'second', 'distinctId' => 'actor']);
    }

    public function testCachedConfigurationKeepsItsValuesAfterTheEnvironmentChanges(): void
    {
        $config = $this->withEnvironment([
            'POSTHOG_CONSUMER' => 'noop', 'POSTHOG_BATCH_SIZE' => '25',
            'POSTHOG_FLUSH_INTERVAL_SECONDS' => '0', 'POSTHOG_COMPRESS_REQUEST' => 'false',
        ], fn () => $this->configuration());
        $cached = eval('return ' . var_export($config, true) . ';');
        $this->withEnvironment([
            'POSTHOG_CONSUMER' => 'socket', 'POSTHOG_BATCH_SIZE' => '100',
            'POSTHOG_FLUSH_INTERVAL_SECONDS' => '10', 'POSTHOG_COMPRESS_REQUEST' => 'true',
        ], function () use ($cached) {
            $client = $this->client($cached);
            $consumer = (new \ReflectionProperty(Client::class, 'consumer'))->getValue($client);
            $this->assertInstanceOf(Consumer\NoOp::class, $consumer);
            $options = (new \ReflectionProperty(Client::class, 'options'))->getValue($client);
            $this->assertSame(25, $options['batch_size']);
            $this->assertSame(0.0, $options['flush_interval_seconds']);
            $this->assertFalse($options['compress_request']);
        });
    }
}
