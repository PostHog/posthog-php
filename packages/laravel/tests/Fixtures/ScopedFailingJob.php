<?php

namespace PostHog\Laravel\Tests\Fixtures;

use PostHog\Laravel\Context;
use PostHog\Laravel\PostHog;
use RuntimeException;

class ScopedFailingJob extends CaptureJob
{
    public function handle(Context $context, PostHog $posthog): void
    {
        $context->with(['distinctId' => $this->id, 'groups' => ['team' => 'job-team']], function () {
            throw new RuntimeException('scoped failure');
        });
    }
}
