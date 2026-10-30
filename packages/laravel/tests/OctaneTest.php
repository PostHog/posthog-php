<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Laravel\Octane\CurrentApplication;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Orchestra\Testbench\TestCase;
use PostHog\Laravel\Context;
use PostHog\Laravel\ExceptionReporter;
use PostHog\Laravel\Facades\PostHog as Facade;
use PostHog\Laravel\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\IgnoredOctaneFailure;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class OctaneTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    public function testRealOctaneEventsUseTwoSandboxContainersAndSeparateClients(): void
    {
        $root = $this->app;
        $root['config']->set('posthog.api_key', 'project-key');
        $root['config']->set('posthog.sdk.consumer', 'noop');
        $clients = [];
        try {
            foreach (['first', 'second'] as $id) {
                $sandbox = clone $root;
                CurrentApplication::set($sandbox);
                $request = Request::create('/', server: ['HTTP_X_POSTHOG_DISTINCT_ID' => $id]);
                $sandbox['events']->dispatch(new RequestReceived($root, $sandbox, $request));
                $context = $sandbox->make(Context::class);
                $this->assertSame($id, $context->data()['distinctId']);
                $clients[] = $sandbox->make(PostHog::class)->client();
                $this->assertSame($clients[array_key_last($clients)], $sandbox->make(\PostHog\Client::class));
                $sandbox['events']->dispatch(new RequestTerminated($root, $sandbox, $request, new Response()));
                $this->assertSame([], $context->data());
                CurrentApplication::set($root);
            }
            $this->assertNotSame($clients[0], $clients[1]);
        } finally {
            CurrentApplication::set($root);
        }
    }

    public function testWorkerFailureReportsThroughDontReportPolicyAndCleansContext(): void
    {
        $sandbox = $this->app;
        $fake = Facade::fake();
        $sandbox['events']->dispatch(new TaskReceived($sandbox, $sandbox, null));
        $sandbox->make(Context::class)->begin(['distinctId' => 'failing-task']);
        $exception = new RuntimeException('failure');
        $sandbox['events']->dispatch(new WorkerErrorOccurred($exception, $sandbox));
        $sandbox->make(ExceptionHandler::class)->report($exception);
        $fake->assertExceptionCaptured(
            fn ($message) => $message['distinctId'] === 'failing-task'
                && !$message['options']['handled']
                && ($message['options']['source'] ?? null) === 'laravel.octane_worker_error'
        );
        $this->assertSame([], $sandbox->make(Context::class)->data());
        $sandbox->make(ExceptionHandler::class)->report($exception);
        $this->assertCount(1, $fake->recorded('captureException'));
        $sandbox->make(ExceptionHandler::class)->dontReport([IgnoredOctaneFailure::class]);
        $ignored = new IgnoredOctaneFailure();
        $sandbox['events']->dispatch(new WorkerErrorOccurred($ignored, $sandbox));
        $sandbox->make(ExceptionHandler::class)->report($ignored);
        $this->assertCount(1, $fake->recorded('captureException'));
        $sandbox->instance('env', 'production');
        $sandbox->make(Context::class)->begin(['distinctId' => 'early-report']);
        $earlyEvent = new WorkerErrorOccurred(new RuntimeException('early failure'), $sandbox);
        (new \Laravel\Octane\Listeners\ReportException())->handle($earlyEvent);
        $sandbox['events']->dispatch($earlyEvent);
        $fake->assertExceptionCaptured(
            fn ($message) => $message['distinctId'] === 'early-report' && !$message['options']['handled']
                && ($message['options']['source'] ?? null) === 'laravel.reportable',
        );
        $this->assertCount(2, $fake->recorded('captureException'));
    }
}
