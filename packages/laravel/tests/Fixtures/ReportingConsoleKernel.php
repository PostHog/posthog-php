<?php

namespace PostHog\Laravel\Tests\Fixtures;

use Illuminate\Foundation\Console\Kernel;

class ReportingConsoleKernel extends Kernel
{
    protected function bootstrappers(): array
    {
        return [];
    }
}
