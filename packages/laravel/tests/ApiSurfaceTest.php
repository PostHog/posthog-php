<?php

namespace PostHog\Laravel\Tests;

use Error;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PostHog\Client;
use PostHog\HttpClient;
use PostHog\HttpResponse;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog as Facade;
use PostHog\Laravel\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Scripts\PublicApiSnapshot;
use ReflectionMethod;

class ApiSurfaceTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'project-key');
        $app['config']->set('posthog.sdk.consumer', 'noop');
    }

    public static function unsupportedMethods(): array
    {
        return [
            'transport metadata' => ['getFlagsEtag', []],
            'internal logger' => ['logWarning', ['not a Laravel API']],
            'exposure plumbing' => ['captureFlagCalledIfNeeded', ['user', 'flag', true, [], []]],
        ];
    }

    #[DataProvider('unsupportedMethods')]
    public function testCoreImplementationMethodsAreNotForwarded(string $method, array $arguments): void
    {
        $this->expectException(Error::class);
        $this->app->make(PostHog::class)->$method(...$arguments);
    }

    public function testFacadeDoesNotForwardCoreImplementationMethods(): void
    {
        $this->expectException(Error::class);
        Facade::getFlagsEtag();
    }

    public function testNewClientMethodsAreNotAutomaticallyExposed(): void
    {
        $client = new class (null, ['consumer' => 'noop'], loadFeatureFlags: false) extends Client {
            public function futureClientMethod(): string
            {
                return 'not a Laravel API';
            }
        };
        $manager = $this->getMockBuilder(PostHog::class)
            ->setConstructorArgs([$this->app])
            ->onlyMethods(['client'])
            ->getMock();
        $manager->expects($this->atLeastOnce())->method('client')->willReturn($client);
        $this->assertSame('not a Laravel API', $manager->client()->futureClientMethod());
        $this->expectException(Error::class);
        $manager->futureClientMethod();
    }

    public static function flagMethods(): array
    {
        $single = [
            'key' => 'checkout', 'distinctId' => 'explicit', 'groups' => ['team' => 'one'],
            'personProperties' => ['plan' => 'pro'], 'groupProperties' => ['team' => ['size' => 2]],
        ];
        $options = ['onlyEvaluateLocally' => true, 'sendFeatureFlagEvents' => false];
        $methods = [
            'boolean' => ['isFeatureEnabled', $single + $options, true],
            'value' => ['getFeatureFlag', $single + $options, 'variant'],
            'result' => ['getFeatureFlagResult', $single + $options, null],
            'payload' => ['getFeatureFlagPayload', $single, ['color' => 'blue']],
            'all' => ['getAllFlags', array_diff_key($single, ['key' => true]) + [
                'onlyEvaluateLocally' => true,
            ], ['checkout' => true]],
        ];
        $cases = $methods;
        foreach ($methods as $name => [$method, $arguments, $result]) {
            $arguments['groups'] = [];
            $cases['ambient groups ' . $name] = [$method, $arguments, $result];
        }
        return $cases;
    }

    #[DataProvider('flagMethods')]
    public function testSupportedFlagMethodsDelegateNamedArgumentsAndRestoreContext(
        string $method,
        array $arguments,
        mixed $result,
    ): void {
        $client = $this->getMockBuilder(Client::class)
            ->setConstructorArgs(['project-key', ['consumer' => 'noop'], null, null, false])
            ->onlyMethods([$method])
            ->getMock();
        $expected = $arguments;
        $expected['groups'] = $expected['groups'] ?: ['team' => 'ambient'];
        $client->expects($this->once())->method($method)->willReturnCallback(
            function (...$actual) use ($client, $expected, $result) {
                $this->assertSame(array_values($expected), $actual);
                $this->assertSame('ambient', $client->getContext()['distinctId']);
                return $result;
            },
        );
        $manager = $this->getMockBuilder(PostHog::class)
            ->setConstructorArgs([$this->app])
            ->onlyMethods(['client'])
            ->getMock();
        $manager->expects($this->atLeastOnce())->method('client')->willReturn($client);
        $this->app->make(Context::class)->begin(['distinctId' => 'ambient', 'groups' => ['team' => 'ambient']]);
        $raw = $client->enterContext(['distinctId' => 'raw-parent']);
        $before = $client->getContext();
        try {
            $this->assertSame($result, $manager->$method(...$arguments));
            $this->assertSame($before, $client->getContext());
        } finally {
            $raw->close();
        }
    }

    public function testLegacyFlagEvaluationSendsAmbientGroupsWhenArgumentsAreOmitted(): void
    {
        $payload = null;
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())->method('sendRequest')->willReturnCallback(
            function ($path, $body) use (&$payload) {
                $payload = json_decode($body, true);
                return new HttpResponse(json_encode([
                    'flags' => ['checkout' => ['enabled' => true, 'variant' => null]],
                ]), 200);
            },
        );
        $client = new Client('project-key', ['consumer' => 'noop'], $http, loadFeatureFlags: false);
        $manager = $this->getMockBuilder(PostHog::class)
            ->setConstructorArgs([$this->app])
            ->onlyMethods(['client'])
            ->getMock();
        $manager->expects($this->atLeastOnce())->method('client')->willReturn($client);
        $this->app->make(Context::class)->begin(['distinctId' => 'user', 'groups' => ['team' => 'ambient']]);
        $this->assertSame(['checkout' => true], $manager->getAllFlags());
        $this->assertSame(['team' => 'ambient'], $payload['groups']);
        $this->assertSame('user', $payload['distinct_id']);
        $this->assertNull($client->getContext());
    }

    public function testSupportedForwardersKeepTheCoreParameterSignatures(): void
    {
        $methods = [
            'isFeatureEnabled', 'getFeatureFlag', 'getFeatureFlagResult', 'getFeatureFlagPayload',
            'getAllFlags', 'loadFlags', 'contextFromHeaders',
        ];
        foreach ($methods as $method) {
            $signature = static fn ($parameter) => [
                $parameter->getName(), (string) $parameter->getType(), $parameter->isOptional(),
                $parameter->isPassedByReference(), $parameter->isVariadic(),
                $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            ];
            $this->assertSame(
                array_map($signature, (new ReflectionMethod(Client::class, $method))->getParameters()),
                array_map($signature, (new ReflectionMethod(PostHog::class, $method))->getParameters()),
                $method,
            );
        }
    }

    public function testPublicApiMatchesItsSnapshot(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/scripts/PublicApiSnapshot.php';
        $builder = new ReflectionMethod(PublicApiSnapshot::class, 'buildPublicApi');
        $api = $builder->invoke(null, dirname(__DIR__) . '/src', 'PostHog\\Laravel\\', true);
        $this->assertArrayNotHasKey(\PostHog\Laravel\ExceptionReporter::class, $api['symbols']);
        $this->assertArrayNotHasKey('fromRequest', $api['symbols'][Context::class]['methods']);
        $this->assertArrayHasKey('getFeatureFlagResult', $api['symbols'][PostHog::class]['methods']);
        $this->assertSame(
            file_get_contents($root . '/api/laravel-public-api.json'),
            json_encode($api, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
