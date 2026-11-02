<?php

namespace PostHog\Laravel\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use PostHog\Laravel\Facades\PostHog;

class AmbientCaptureJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        PostHog::capture('ambient job');
    }
}
