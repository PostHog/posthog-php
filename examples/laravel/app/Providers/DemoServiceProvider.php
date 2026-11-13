<?php

namespace App\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ServiceProvider;
use PostHog\Laravel\Context;

final class DemoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event): void {
            if (in_array($event->command, ['demo:posthog', 'demo:scheduled', 'schedule:run'], true)) {
                // Synthetic diagnostic identity, not attribution to an authenticated person.
                // Runtime command cleanup closes this scope after outcome collectors finish.
                app(Context::class)->begin([
                    'distinctId' => 'demo-console',
                    'groups' => ['team' => 'demo-team'],
                    'properties' => ['component' => 'demo-console'],
                ]);
            }
        });
    }
}
