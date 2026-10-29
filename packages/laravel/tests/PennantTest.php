<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
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
use PostHog\Laravel\Tests\Fixtures\CaptureJob;
use PostHog\Laravel\Tests\Fixtures\FailingCaptureJob;
use PostHog\Laravel\Tests\Fixtures\PennantEndEventProvider;
use PostHog\Laravel\Tests\Fixtures\ScopedCommand;
use RuntimeException;

class PennantTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PennantServiceProvider::class, PostHogServiceProvider::class, PennantEndEventProvider::class];
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

    public function testExplicitScopeNameResolutionDoesNotEvaluateTheAmbientIdentity(): void
    {
        $events = [];
        $this->sdk(1, $events, 'explicit-b');
        $this->app->make(Context::class)->begin(['distinctId' => 'ambient-a']);

        $this->assertSame('explicit-b', Feature::for('explicit-b')->value('layout'));
        $this->assertTrue(Feature::for('explicit-b')->active('checkout'));
        $this->assertFalse(Feature::for('explicit-b')->active('disabled'));
        $this->assertFalse(Feature::for('explicit-b')->active('unknown'));
        $this->assertSame([], Feature::defined());
    }

    public function testDefinedListsNoLocalDefinitionsAndDoesNotPerformIo(): void
    {
        $events = [];
        $this->sdk(0, $events);
        $this->app->make(Context::class)->begin(['distinctId' => 'ambient-a']);

        $this->assertSame([], Feature::defined());
        $this->assertSame([], Feature::defined());
        $this->assertSame([], $events);
    }

    public function testAllDiscoversExternalFlagsForTheExplicitScope(): void
    {
        $events = [];
        $this->sdk(1, $events, 'explicit-b', [
            'b-only' => ['enabled' => true, 'variant' => null],
            'unresolved' => ['failed' => true],
        ]);
        $this->app->make(Context::class)->begin(['distinctId' => 'ambient-a']);

        $this->assertSame([
            'layout' => 'explicit-b',
            'checkout' => true,
            'disabled' => false,
            'b-only' => true,
        ], Feature::for('explicit-b')->all());
        $this->assertCount(4, $events);
        $this->assertSame('explicit-b', Feature::for('explicit-b')->value('layout'));
        $this->assertCount(4, $events);
        $this->assertFalse(Feature::for('explicit-b')->active('unresolved'));
        $this->assertFalse(Feature::for('explicit-b')->active('unknown'));
    }

    public function testLoadAllNormalizesTheScopeAndReusesTheNativeSnapshot(): void
    {
        $events = [];
        $this->sdk(1, $events, 'custom-posthog');
        $this->app->make(Context::class)->begin(['distinctId' => 'ambient-a']);
        $scope = new class implements FeatureScopeable {
            public function toFeatureIdentifier(string $driver): mixed
            {
                return 'custom-' . $driver;
            }
        };

        $this->assertSame([
            'layout' => ['custom-posthog'],
            'checkout' => [true],
            'disabled' => [false],
        ], Feature::for($scope)->loadAll());
        $this->assertSame('custom-posthog', Feature::for($scope)->value('layout'));
        $this->assertTrue(Feature::for($scope)->active('checkout'));
        $this->assertFalse(Feature::for($scope)->active('disabled'));
        $this->assertCount(3, $events);

        Feature::flushCache();
        $this->assertSame('custom-posthog', Feature::for($scope)->value('layout'));
        $this->assertSame('custom-posthog', $this->app->make(PostHog::class)
            ->evaluateFlags('custom-posthog')->getFlag('layout'));
        $this->assertCount(3, $events);
    }

    public function testScopedKeyEnumerationDoesNotRecordExposure(): void
    {
        $events = [];
        $this->sdk(1, $events, 'explicit-b');
        $this->app->make(Context::class)->begin(['distinctId' => 'ambient-a']);

        $this->assertSame(
            ['layout', 'checkout', 'disabled'],
            Feature::definedFeaturesForScope('explicit-b')->all(),
        );
        $this->assertSame([], $events);
        $this->assertSame([], Feature::defined());
        $this->assertSame('explicit-b', Feature::for('explicit-b')->value('layout'));
        $this->assertCount(1, $events);
    }

    public function testBulkReadsWithoutAnIdentityAreEmptyAndDoNotPerformIo(): void
    {
        $events = [];
        $this->sdk(0, $events);

        $this->assertSame([], Feature::all());
        $this->assertSame([], Feature::loadAll());
        $this->assertFalse(Feature::value('unknown'));
        $this->assertSame([], $events);
    }

    public function testDisabledBulkReadsAreEmptyAndDoNotPerformIo(): void
    {
        $events = [];
        $this->sdk(0, $events);
        $this->app['config']->set('posthog.enabled', false);
        $this->app->make(Context::class)->begin(['distinctId' => 'ambient-a']);

        $this->assertSame([], Feature::for('explicit-b')->all());
        $this->assertSame([], Feature::for('explicit-b')->loadAll());
        $this->assertSame([], $events);
        $this->assertFalse(Feature::for('explicit-b')->value('unknown'));
    }

    public static function scopedCallers(): array
    {
        return [
            'anonymous caller, success' => [null, false],
            'anonymous caller, failure' => [null, true],
            'identified caller, success' => ['parent-owner', false],
            'identified caller, failure' => ['parent-owner', true],
        ];
    }

    #[DataProvider('scopedCallers')]
    public function testQueueCompletionConsumersCannotPolluteTheRestoredCallersCache(
        ?string $parentId,
        bool $fail,
    ): void {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('pennant-test.read-on-job-end', true);
        $events = [];
        $this->sdk($parentId === null ? 1 : 2, $events);
        $context = $this->app->make(Context::class);
        $context->begin($parentId === null ? [] : ['distinctId' => $parentId]);
        $this->assertSame($parentId ?? false, Feature::value('layout'));
        $parentSnapshot = $this->app->make(PostHog::class)->evaluateFlags();
        try {
            Bus::dispatchSync($fail ? new FailingCaptureJob('job-owner') : new CaptureJob('job-owner'));
            $this->assertFalse($fail, 'The failing job must rethrow its original exception');
        } catch (RuntimeException $exception) {
            $this->assertTrue($fail);
            $this->assertSame('failed job', $exception->getMessage());
        }
        $this->assertSame('job-owner', $this->app['config']->get('pennant-test.job-value'));
        $this->assertSame($parentId, $context->data()['distinctId'] ?? null);
        $this->assertSame($parentId ?? false, Feature::value('layout'));
        if ($parentId !== null) {
            $this->assertSame($parentSnapshot, $this->app->make(PostHog::class)->evaluateFlags());
        }
    }

    #[DataProvider('scopedCallers')]
    public function testDirectCommandCallsClearPennantCacheOnEntryAndAfterCallerRestoration(
        ?string $parentId,
        bool $fail,
    ): void {
        $events = [];
        $this->sdk($parentId === null ? 1 : 2, $events);
        $context = $this->app->make(Context::class);
        $observed = [];
        Artisan::command('posthog:child', function () use ($context, $fail, &$observed) {
            $context->begin(['distinctId' => 'child-owner']);
            $observed['child'] = Feature::value('layout');
            if ($fail) {
                throw new RuntimeException('child failed');
            }
            return 0;
        });
        Artisan::registerCommand(new ScopedCommand('posthog:parent', function (Command $command) use (
            $context,
            $parentId,
            &$observed,
        ) {
            $context->begin($parentId === null ? [] : ['distinctId' => $parentId]);
            $observed['before'] = Feature::value('layout');
            $observed['beforeSnapshot'] = $this->app->make(PostHog::class)->evaluateFlags();
            $observed['exception'] = null;
            try {
                $command->call('posthog:child');
            } catch (RuntimeException $exception) {
                $observed['exception'] = $exception->getMessage();
            }
            $observed['after'] = Feature::value('layout');
            $observed['afterSnapshot'] = $this->app->make(PostHog::class)->evaluateFlags();
            return 0;
        }));

        $this->assertSame(0, Artisan::call('posthog:parent'));
        $this->assertSame($parentId ?? false, $observed['before']);
        $this->assertSame('child-owner', $observed['child']);
        $this->assertSame($parentId ?? false, $observed['after']);
        $this->assertSame($fail ? 'child failed' : null, $observed['exception']);
        if ($parentId !== null) {
            $this->assertSame($observed['beforeSnapshot'], $observed['afterSnapshot']);
        }
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

    private function sdk(
        int $requests,
        array &$events,
        ?string $expectedIdentity = null,
        array $extraFlags = [],
    ): void {
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->exactly($requests))->method('sendRequest')->willReturnCallback(function (
            $path,
            $payload,
        ) use (
            $expectedIdentity,
            $extraFlags,
        ) {
            $this->assertSame('/flags/?v=2', $path);
            $id = json_decode($payload, true)['distinct_id'];
            if ($expectedIdentity !== null) {
                $this->assertSame($expectedIdentity, $id);
            }
            return new HttpResponse(json_encode(['flags' => [
                'layout' => ['enabled' => true, 'variant' => $id, 'metadata' => ['payload' => '{"theme":"dark"}']],
                'checkout' => ['enabled' => true, 'variant' => null],
                'disabled' => ['enabled' => false, 'variant' => null],
            ] + $extraFlags]), 200);
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
