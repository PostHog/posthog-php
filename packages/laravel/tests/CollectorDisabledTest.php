<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PostHog\Laravel\Autocapture\Collector;
use PostHog\Laravel\Autocapture\DeferredModelEvents;
use PostHog\Laravel\Autocapture\RequestSummary;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\CollectedRecord;
use PostHog\Laravel\Tests\Fixtures\OrderCompleted;

class CollectorDisabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'project-key');
        $app['config']->set('posthog.sdk.consumer', 'noop');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        foreach (['requests', 'auth', 'models', 'jobs', 'blade'] as $section) {
            $app['config']->set('posthog.autocapture.' . $section . '.enabled', true);
        }
        $app['config']->set('posthog.autocapture.models.allow', [CollectedRecord::class => ['state']]);
        $app['config']->set('posthog.autocapture.domain_events', [OrderCompleted::class => [
            'event' => 'order_completed', 'properties' => ['amount' => 'amount'],
        ]]);
    }

    public static function disabledConfigurations(): array
    {
        return [
            'disabled' => [false, 'project-key'],
            'empty key' => [true, ''],
            'whitespace key' => [true, '   '],
        ];
    }

    #[DataProvider('disabledConfigurations')]
    public function testDisabledCollectorsDoNotResolveContextOrBufferModels(bool $enabled, string $key): void
    {
        $this->app['config']->set('posthog.enabled', $enabled);
        $this->app['config']->set('posthog.api_key', $key);
        $calls = ['identity' => 0, 'groups' => 0, 'user' => 0];
        foreach (['identity' => 'distinct_id', 'groups' => 'groups'] as $name => $option) {
            $this->app->instance('collector.' . $name, function () use (&$calls, $name) {
                $calls[$name]++;
                return $name === 'groups' ? [] : null;
            });
            $this->app['config']->set('posthog.' . $option, 'collector.' . $name);
        }
        $request = Request::create('/disabled');
        $request->setUserResolver(function () use (&$calls) {
            $calls['user']++;
            return new GenericUser(['id' => 'actor']);
        });
        $this->app->make(Context::class)->fromRequest($request);
        DB::getSchemaBuilder()->create('collected_records', function (Blueprint $table) {
            $table->id();
            $table->string('state');
            $table->string('email');
        });
        DB::beginTransaction();
        try {
            for ($i = 0; $i < 50; $i++) {
                CollectedRecord::create(['state' => 'new', 'email' => 'private']);
            }
            $this->assertSame(
                ['count' => 0, 'bytes' => 0],
                $this->app->make(DeferredModelEvents::class)->pendingUsage(),
            );
            $this->app['events']->dispatch(new OrderCompleted());
            $this->app['events']->dispatch(new \Illuminate\Auth\Events\Login(
                'web',
                new GenericUser(['id' => 1]),
                false,
            ));
            $response = response('ok');
            $this->assertSame($response, (new RequestSummary())->handle($request, fn () => $response));
            Collector::capture('direct collector call', []);
            $this->assertSame(['identity' => 0, 'groups' => 0, 'user' => 0], $calls);
        } finally {
            DB::rollBack();
        }
    }

    public function testFakeStillCollectsWithDisabledConfigurationAndNoKey(): void
    {
        $this->app['config']->set('posthog.enabled', false);
        $this->app['config']->set('posthog.api_key', '');
        $fake = PostHog::fake();
        $this->app->make(Context::class)->begin(['distinctId' => 'actor']);
        $this->app['events']->dispatch(new OrderCompleted());
        $fake->assertCaptured('order_completed');
    }
}
