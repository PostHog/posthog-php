<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;

Artisan::command('demo:scheduled', function (): void {
    app(Context::class)->with([
        'distinctId' => 'demo-scheduler',
        'properties' => ['component' => 'scheduler'],
    ], fn () => PostHog::capture('demo_scheduled_tick'));
    $this->info('Synthetic scheduled tick captured (or disabled without a key).');
})->purpose('Run a synthetic scheduled task');

Schedule::command('demo:scheduled')->everyMinute()->name('demo-scheduled-tick');
