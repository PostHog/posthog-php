<?php

namespace PostHog\Laravel\Tests\Fixtures;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

class PennantEndEventProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['events']->listen([JobProcessed::class, JobExceptionOccurred::class], function (): void {
            if ($this->app['config']->get('pennant-test.read-on-job-end', false)) {
                $this->app['config']->set('pennant-test.job-value', Feature::value('layout'));
            }
        });
    }
}
