<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHogServiceProvider;

class SchedulerOutcomeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'test-key');
        $app['config']->set('posthog.autocapture.scheduler.enabled', true);
    }

    public static function taskModes(): array
    {
        return ['foreground' => [false], 'background' => [true]];
    }

    #[DataProvider('taskModes')]
    public function testOverlapLockRacesDoNotProduceAnOutcome(bool $background): void
    {
        $fake = PostHog::fake();
        $this->app->make(Context::class)->begin(['distinctId' => 'scheduler-actor']);
        $mutex = $this->createMock(EventMutex::class);
        $mutex->method('exists')->willReturn(false);
        $mutex->expects($this->exactly(2))->method('create')->willReturnOnConsecutiveCalls(false, true);
        $this->app->instance(EventMutex::class, $mutex);
        $schedule = $this->app->make(Schedule::class);
        $task = $schedule->exec(PHP_BINARY, ['-r', 'exit(0);'])->withoutOverlapping();
        $task->runInBackground = $background;

        $this->assertSame(0, Artisan::call('schedule:run'));
        $fake->assertNotCaptured('laravel_scheduled_task');

        $task->runInBackground = false;
        $this->assertSame(0, Artisan::call('schedule:run'));
        $fake->assertCapturedCount(1);
        $fake->assertCaptured('laravel_scheduled_task', fn ($event) =>
            $event['distinctId'] === 'scheduler-actor'
            && $event['properties']['outcome'] === 'success'
            && $event['properties']['exit_code'] === 0);
    }
}
