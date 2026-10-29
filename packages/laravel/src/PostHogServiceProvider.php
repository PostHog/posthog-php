<?php

namespace PostHog\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\Looping;
use PostHog\Laravel\Middleware\RequestContext as RequestContextMiddleware;
use Illuminate\Support\ServiceProvider;
use Throwable;

class PostHogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/posthog.php', 'posthog');
        $this->app->scoped(Context::class);
        $this->app->scoped(PostHog::class);
        $this->app->alias(PostHog::class, 'posthog');
        $this->app->scoped(ExceptionReporter::class);
        $this->app->scoped(\PostHog\Client::class, fn ($app) => $app->make(PostHog::class)->client());
        $this->app->scoped(FlagDefinitionCacheProvider::class, function ($app) {
            $config = $app['config']->get('posthog');
            $key = 'posthog:definitions:' . hash('sha256', ($config['host'] ?? '') . ':' . ($config['api_key'] ?? ''));
            return new FlagDefinitionCacheProvider(
                $app['cache']->store($config['flag_cache']['store']),
                $key,
                $config['flag_cache']['ttl'],
                $config['flag_cache']['refresh_interval'],
            );
        });
        if (class_exists(AutocaptureServiceProvider::class)) {
            $this->app->register(AutocaptureServiceProvider::class);
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/posthog.php' => config_path('posthog.php')], 'posthog-config');
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\DoctorCommand::class,
                Commands\InstallCommand::class,
                Commands\TestCommand::class,
            ]);
        }
        $this->callAfterResolving(Kernel::class, function ($kernel) {
            $kernel->prependMiddleware(RequestContextMiddleware::class);
        });
        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $exception) {
                    Container::getInstance()->make(ExceptionReporter::class)->report($exception);
                });
            }
        });
        $events = $this->app['events'];
        QueueContext::register();
        $events->listen(JobProcessing::class, function ($event) {
            $app = Container::getInstance();
            $context = $app->make(Context::class);
            $context->enter();
            $data = $event->job->payload()['_posthog'] ?? null;
            if ($app['config']->get('posthog.queue_context', true) && is_array($data)) {
                $context->begin(QueueContext::normalize($data));
            }
        });
        $events->listen(JobExceptionOccurred::class, function ($event) {
            $app = Container::getInstance();
            $app->make(ExceptionReporter::class)->remember($event->exception, 'task');
            $this->finish($app, true);
        });
        $events->listen(JobProcessed::class, fn () => $this->finish(Container::getInstance(), true));
        foreach (
            [
                CommandStarting::class,
                'Laravel\\Octane\\Events\\RequestReceived',
                'Laravel\\Octane\\Events\\TaskReceived',
                'Laravel\\Octane\\Events\\TickReceived',
            ] as $event
        ) {
            $events->listen($event, function ($event) {
                $context = ($event->sandbox ?? $event->app ?? Container::getInstance())->make(Context::class);
                if (isset($event->request)) {
                    $context->fromRequest($event->request);
                } else {
                    $context->begin();
                }
            });
        }
        foreach (
            [
                Looping::class,
                CommandFinished::class,
                'Laravel\\Octane\\Events\\RequestTerminated',
                'Laravel\\Octane\\Events\\TaskTerminated',
                'Laravel\\Octane\\Events\\TickTerminated',
            ] as $event
        ) {
            $events->listen($event, function ($event) {
                $this->finish($event->sandbox ?? $event->app ?? Container::getInstance());
            });
        }
        $events->listen('Laravel\\Octane\\Events\\WorkerErrorOccurred', function ($event) {
            $app = $event->sandbox;
            $app->make(ExceptionReporter::class)->remember($event->exception, 'runtime');
            $this->finish($app);
        });
        $this->app->terminating(fn () => $this->finish(Container::getInstance()));
    }

    private function finish(Container $app, bool $queue = false): void
    {
        try {
            $app->make(PostHog::class)->flush();
        } catch (Throwable) {
            // Lifecycle cleanup must continue when a transport fails.
        } finally {
            $context = $app->make(Context::class);
            $queue ? $context->leave() : $context->end();
        }
    }
}
