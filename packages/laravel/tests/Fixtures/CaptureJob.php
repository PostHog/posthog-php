<?php

namespace PostHog\Laravel\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use PostHog\Laravel\Context;
use PostHog\Laravel\PostHog;

class CaptureJob implements ShouldQueue
{
    use Dispatchable;
    use \Illuminate\Bus\Queueable;

    public function __construct(public string $id)
    {
    }

    public function handle(Context $context, PostHog $posthog): void
    {
        $context->begin(['distinctId' => $this->id]);
        $posthog->capture('job');
    }
}
