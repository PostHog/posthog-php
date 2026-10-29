<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;

final class DemoJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $analyticsId)
    {
    }

    public function handle(Context $context): void
    {
        PostHog::capture('demo_job_ambient', ['rows' => 3]);
        // Explicit job scope may override the safely propagated analytics identity.
        $context->with([
            'distinctId' => $this->analyticsId,
            'groups' => ['team' => 'demo-team'],
            'properties' => ['component' => 'demo-job'],
        ], fn () => PostHog::capture('demo_job_completed', ['rows' => 3]));
    }
}
