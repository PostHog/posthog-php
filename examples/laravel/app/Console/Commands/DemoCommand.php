<?php

namespace App\Console\Commands;

use App\Support\DemoActions;
use Illuminate\Console\Command;
use PostHog\Laravel\Facades\PostHog;

final class DemoCommand extends Command
{
    protected $signature = 'demo:posthog {--flags : Evaluate flags (may contact configured host)}';
    protected $description = 'Capture synthetic analytics, identity, exception, order, and queue examples';

    public function handle(DemoActions $demo): int
    {
        $result = [
            'capture' => $demo->capture(),
            'identity' => $demo->identity(),
            'exception' => $demo->exception(),
            'order' => $demo->order(),
            'queue' => $demo->queue(),
        ];
        if ($this->option('flags')) {
            $result['flags'] = $demo->flags();
        }
        $result['flushed'] = PostHog::flush();
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
