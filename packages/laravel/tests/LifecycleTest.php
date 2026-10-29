<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\AmbientCaptureJob;
use PostHog\Laravel\Tests\Fixtures\ReportingConsoleKernel;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class LifecycleTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'test-key');
        $app['config']->set('posthog.sdk.consumer', 'noop');
        $app['config']->set('queue.default', 'sync');
    }

    public function testAfterResponseAndLateTerminatingJobsKeepRequestIdentityAndSession(): void
    {
        $fake = PostHog::fake();
        Route::get('/deferred', function () {
            $this->app['auth']->setUser(new GenericUser(['id' => 'request-owner']));
            Bus::dispatchAfterResponse(new AmbientCaptureJob());
            $this->app->terminating(function () {
                Bus::dispatchAfterResponse(new AmbientCaptureJob());
            });
            return 'ok';
        });

        $this->get('/deferred', ['X-PostHog-Session-Id' => 'request-session'])->assertOk();
        $messages = array_column($fake->recorded('capture'), 'message');
        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            $this->assertSame('request-owner', $message['distinctId']);
            $this->assertSame('request-session', $message['properties']['$session_id']);
        }
        $this->assertSame([], $this->app->make(Context::class)->snapshot());
    }

    public function testChildFailureReturnCannotIdentifyAnIndependentlyThrowingParent(): void
    {
        $fake = PostHog::fake();
        $kernel = $this->reportingKernel();
        Artisan::command('posthog:child', function () {
            app(Context::class)->begin(['distinctId' => 'child']);
            return 1;
        });
        Artisan::command('posthog:parent', function () {
            app(Context::class)->begin(['distinctId' => 'parent']);
            Artisan::call('posthog:child');
            throw new RuntimeException('parent failed');
        });
        $input = new ArrayInput(['command' => 'posthog:parent']);
        $this->assertSame(1, $kernel->handle($input, new NullOutput()));
        $kernel->terminate($input, 1);
        $fake->assertExceptionCaptured(fn ($event) => $event['distinctId'] === 'parent'
            && $event['options']['handled'] === false);
        $this->assertSame([], $this->app->make(Context::class)->snapshot());
    }

    public function testThrowingChildKeepsItsOwnExceptionSnapshotAfterCallerCatchesIt(): void
    {
        $fake = PostHog::fake();
        $kernel = $this->reportingKernel();
        Artisan::command('posthog:throwing-child', function () {
            app(Context::class)->begin(['distinctId' => 'child']);
            throw new RuntimeException('child failed');
        });
        Artisan::command('posthog:catching-parent', function () {
            app(Context::class)->begin(['distinctId' => 'parent']);
            try {
                Artisan::call('posthog:throwing-child');
            } catch (RuntimeException $exception) {
                report($exception);
            }
            PostHog::capture('parent continued');
            return 0;
        });
        $input = new ArrayInput(['command' => 'posthog:catching-parent']);
        $this->assertSame(0, $kernel->handle($input, new NullOutput()));
        $kernel->terminate($input, 0);
        $fake->assertExceptionCaptured(fn ($event) => $event['distinctId'] === 'child');
        $fake->assertCaptured('parent continued', fn ($event) => $event['distinctId'] === 'parent');
    }

    private function reportingKernel(): ConsoleKernel
    {
        $this->app->singleton(ConsoleKernel::class, fn ($app) => new ReportingConsoleKernel($app, $app['events']));
        Artisan::clearResolvedInstance(ConsoleKernel::class);
        $kernel = $this->app->make(ConsoleKernel::class);
        $kernel->rerouteSymfonyCommandEvents();
        return $kernel;
    }
}
