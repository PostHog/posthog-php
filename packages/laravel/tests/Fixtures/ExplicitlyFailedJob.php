<?php

namespace PostHog\Laravel\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

class ExplicitlyFailedJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        $this->fail(new RuntimeException('explicit job failure'));
    }
}
