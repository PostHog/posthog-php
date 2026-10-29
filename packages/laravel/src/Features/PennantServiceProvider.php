<?php

namespace PostHog\Laravel\Features;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\FeatureManager;

final class PennantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app['config']->get('pennant.stores.posthog') === null) {
            $this->app['config']->set('pennant.stores.posthog', ['driver' => 'posthog']);
        }
        $this->callAfterResolving(FeatureManager::class, function ($manager) {
            $manager->extend('posthog', function () {
                return new PostHogDriver();
            });
        });
        $this->callAfterResolving(Kernel::class, fn ($kernel) => $kernel->prependMiddleware(PennantMiddleware::class));
        $this->app['events']->listen([
            \Illuminate\Queue\Events\JobProcessing::class,
            \Illuminate\Queue\Events\JobProcessed::class,
            \Illuminate\Queue\Events\JobExceptionOccurred::class,
            \Illuminate\Console\Events\CommandStarting::class,
            \Illuminate\Console\Events\CommandFinished::class,
        ], fn () => PennantCache::flush());
    }
}
