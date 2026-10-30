<?php

namespace PostHog\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PostHog\Laravel\PostHog;

/** @internal */
final class TestCommand extends Command
{
    protected $signature = 'posthog:test {--send : Explicitly allow a diagnostic event to be sent}';
    protected $description = 'Send an opt-in diagnostic event without user data';

    public function handle(PostHog $posthog): int
    {
        if (!$this->option('send') || !$posthog->enabled()) {
            $this->warn('No event sent. Configure PostHog and pass --send to opt in.');
            return self::FAILURE;
        }
        $captured = $posthog->withContext(
            [],
            fn () => $posthog->capture('posthog test event', [], 'posthog-test-' . Str::uuid()),
            ['fresh' => true],
        );
        $flushed = $posthog->flush();
        if (!$captured || !$flushed) {
            $this->error('The diagnostic event was not accepted or the transport did not flush.');
            return self::FAILURE;
        }
        $this->info('Diagnostic event queued and flushed. Check your PostHog project for delivery.');
        return self::SUCCESS;
    }
}
