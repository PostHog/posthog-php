<?php

namespace PostHog\Laravel\Tests\Fixtures;

use PostHog\Laravel\Context;
use PostHog\Laravel\PostHog;
use RuntimeException;

class FailingCaptureJob extends CaptureJob
{
    public function handle(Context $context, PostHog $posthog): void
    {
        parent::handle($context, $posthog);
        throw new RuntimeException('failed job');
    }
}
