<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Contracts\FeatureScopeable;
use Laravel\Pennant\Feature;
use Laravel\Pennant\PennantServiceProvider;
use LogicException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PostHog\Client;
use PostHog\HttpClient;
use PostHog\HttpResponse;
use PostHog\Laravel\Context;
use PostHog\Laravel\Features\PostHogDriver;
use PostHog\Laravel\PostHog;
use PostHog\Laravel\PostHogServiceProvider;

class PennantTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PennantServiceProvider::class, PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'test-key');
        $app['config']->set('pennant.default', 'posthog');
    }

    public function testValuesUseOneSnapshotPerIdentityAndUnknownFlagsAreInactive(): void
    {
        $events = [];
        $this->sdk(2, $events);
        $this->assertSame('first', Feature::for('first')->value('layout'));
        $this->assertSame('first', Feature::for('first')->value('layout'));
        $this->assertTrue(Feature::for('first')->active('checkout'));
        $this->assertFalse(Feature::for('first')->active('unknown'));
        $this->assertFalse(Feature::for('first')->active('disabled'));
        $this->assertSame('second', Feature::for('second')->value('layout'));
        $payload = $this->app->make(PostHog::class)->evaluateFlags('first')->getFlagPayload('layout');
        $this->assertSame(['theme' => 'dark'], $payload);
        $layoutExposures = array_filter($events, fn ($event) => $event['event'] === '$feature_flag_called'
            && $event['distinct_id'] === 'first' && $event['properties']['$feature_flag'] === 'layout');
        $this->assertCount(1, $layoutExposures);
    }

    public function testAnonymousPennantScopeDoesNotCacheAnotherRequestsIdentity(): void
    {
        $events = [];
        $this->sdk(2, $events);
        Route::get('/pennant/{id}', function ($id) {
            return $this->app->make(Context::class)->with(['distinctId' => $id], fn () => Feature::value('layout'));
        });
        $this->get('/pennant/first')->assertSee('first');
        $this->get('/pennant/second')->assertSee('second');
    }

    public function testModelScopeUsesTheSameIdentifierAsNativeCapture(): void
    {
        $events = [];
        $this->sdk(1, $events);
        $user = new \Illuminate\Foundation\Auth\User();
        $user->setKeyType('string');
        $user->setRawAttributes(['id' => 'account-123']);
        $this->assertSame('account-123', Feature::for($user)->value('layout'));
        $this->assertSame('account-123', $events[0]['distinct_id']);
    }

    public function testPennantScopeContractIsResolvedBeforeTheDriverReceivesIt(): void
    {
        $events = [];
        $this->sdk(1, $events);
        $scope = new class implements FeatureScopeable {
            public function toFeatureIdentifier(string $driver): mixed
            {
                return 'custom-' . $driver;
            }
        };
        $this->assertSame('custom-posthog', Feature::for($scope)->value('layout'));
        $this->assertSame('custom-posthog', $events[0]['distinct_id']);
    }

    public function testModelScopeContractTakesPrecedenceOverItsAuthenticationIdentifier(): void
    {
        $events = [];
        $this->sdk(1, $events);
        $user = new class extends \Illuminate\Foundation\Auth\User implements FeatureScopeable {
            public function toFeatureIdentifier(string $driver): mixed
            {
                return 'model-' . $driver;
            }
        };
        $user->setRawAttributes(['id' => 123]);
        $this->assertSame('model-posthog', Feature::for($user)->value('layout'));
        $this->assertSame('model-posthog', $events[0]['distinct_id']);
    }

    public static function writes(): array
    {
        return [
            ['define', ['remote', null]],
            ['set', ['remote', 'user', true]],
            ['setForAllScopes', ['remote', true]],
            ['delete', ['remote', 'user']],
            ['purge', [null]],
        ];
    }

    #[DataProvider('writes')]
    public function testRemoteFlagsCannotBeMutated(string $method, array $arguments): void
    {
        if ($method === 'define') {
            $arguments[1] = fn () => true;
        }
        $this->expectException(LogicException::class);
        (new PostHogDriver())->$method(...$arguments);
    }

    private function sdk(int $requests, array &$events): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->exactly($requests))->method('sendRequest')->willReturnCallback(function (
            $path,
            $payload,
        ) {
            $id = json_decode($payload, true)['distinct_id'];
            return new HttpResponse(json_encode(['flags' => [
                'layout' => ['enabled' => true, 'variant' => $id, 'metadata' => ['payload' => '{"theme":"dark"}']],
                'checkout' => ['enabled' => true, 'variant' => null],
                'disabled' => ['enabled' => false, 'variant' => null],
            ]]), 200);
        });
        $sdk = new Client('test-key', [
            'consumer' => 'noop',
            'before_send' => function ($event) use (&$events) {
                $events[] = $event;
                return $event;
            },
        ], $http, loadFeatureFlags: false);
        (new \ReflectionProperty(PostHog::class, 'sdk'))->setValue($this->app->make(PostHog::class), $sdk);
    }
}
