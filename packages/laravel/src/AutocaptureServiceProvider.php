<?php

namespace PostHog\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use PostHog\Laravel\Autocapture\AuthEvents;
use PostHog\Laravel\Autocapture\Collector;
use PostHog\Laravel\Autocapture\DeferredModelEvents;
use PostHog\Laravel\Autocapture\DomainEvents;
use PostHog\Laravel\Autocapture\ModelEvents;
use PostHog\Laravel\Autocapture\Outcomes;
use PostHog\Laravel\Autocapture\RequestSummary;
use Throwable;

class AutocaptureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/autocapture.php', 'posthog-autocapture');
        $config = $this->app['config'];
        $settings = $config->get('posthog-autocapture', []);
        foreach ($config->get('posthog.autocapture', []) as $section => $values) {
            $settings[$section] = $section !== 'domain_events' && is_array($values)
                ? array_replace($settings[$section] ?? [], $values) : $values;
        }
        $config->set('posthog.autocapture', $settings);
        $this->app->singleton(
            DeferredModelEvents::class,
            fn ($app) => new DeferredModelEvents($app['db.transactions']),
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/autocapture.php' => config_path('posthog-autocapture.php'),
        ], 'posthog-autocapture-config');
        $this->callAfterResolving(Kernel::class, fn ($kernel) => $kernel->prependMiddleware(RequestSummary::class));
        $events = $this->app['events'];
        foreach (['Registered', 'Login', 'Logout', 'Failed', 'Lockout'] as $name) {
            $events->listen('Illuminate\\Auth\\Events\\' . $name, static function ($event) use ($name) {
                (new AuthEvents())->collect(strtolower($name), $event);
            });
        }
        foreach (
            [
                'TransactionBeginning' => 'beginning',
                'TransactionCommitting' => 'committing',
                'TransactionRolledBack' => 'rolledBack',
            ] as $event => $method
        ) {
            $events->listen('Illuminate\\Database\\Events\\' . $event, [DeferredModelEvents::class, $method]);
        }
        foreach (['created', 'updated', 'deleted'] as $operation) {
            $events->listen('eloquent.' . $operation . ': *', static function ($name, $payload) use ($operation) {
                (new ModelEvents())->collect($operation, $payload[0]);
            });
        }
        foreach ($this->app['config']->get('posthog.autocapture.domain_events', []) as $class => $mapping) {
            if (is_string($class) && is_array($mapping)) {
                $events->listen($class, static function ($event) use ($class, $mapping) {
                    if (is_object($event) && $event::class === $class) {
                        (new DomainEvents())->collect($event, $mapping);
                    }
                });
            }
        }
        $this->registerOutcomes($events);
        $this->callAfterResolving('blade.compiler', static function ($blade) {
            $blade->if('posthogFeature', static function (string $key): bool {
                if (!Collector::enabled('blade')) {
                    return false;
                }
                try {
                    return Container::getInstance()->make(PostHog::class)->evaluateFlags()->isEnabled($key);
                } catch (Throwable) {
                    return false;
                }
            });
        });
    }

    private function registerOutcomes(Dispatcher $events): void
    {
        $outcomes = new Outcomes();
        $events->listen(\Illuminate\Queue\Events\JobProcessing::class, static function ($event) use ($outcomes) {
            $outcomes->start('jobs', $event->job);
        });
        foreach (
            [
                'JobProcessed' => 'success',
                'JobExceptionOccurred' => 'failed',
                'JobFailed' => 'failed',
            ] as $event => $outcome
        ) {
            $events->listen('Illuminate\\Queue\\Events\\' . $event, static function ($event) use ($outcomes, $outcome) {
                $outcomes->finish('jobs', $event->job, static fn () => [
                    'name' => $event->job->resolveQueuedJobClass(),
                    'outcome' => $outcome, 'attempts' => $event->job->attempts(),
                ]);
            });
        }
        $events->listen(\Illuminate\Console\Events\CommandStarting::class, static function ($event) use ($outcomes) {
            $outcomes->start('commands', $event->input);
        });
        $events->listen(\Illuminate\Console\Events\CommandFinished::class, static function ($event) use ($outcomes) {
            $outcomes->finish('commands', $event->input, static fn () => [
                'name' => preg_split('/\s+/', trim($event->command ?? ''))[0],
                'outcome' => $event->exitCode === 0 ? 'success' : 'failed', 'exit_code' => $event->exitCode,
            ]);
        });
        $events->listen(
            \Illuminate\Console\Events\ScheduledTaskStarting::class,
            static function ($event) use ($outcomes) {
                $outcomes->startScheduler($event->task);
            },
        );
        foreach (['ScheduledTaskFinished' => false, 'ScheduledTaskFailed' => true] as $event => $failed) {
            $events->listen(
                'Illuminate\\Console\\Events\\' . $event,
                static function ($event) use ($outcomes, $failed) {
                    $outcomes->finishScheduler($event->task, $failed);
                },
            );
        }
    }
}
