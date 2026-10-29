<?php

namespace PostHog\Laravel\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'posthog:install';
    protected $description = 'Publish the PostHog configuration without changing application credentials';

    public function handle(): int
    {
        $result = $this->call('vendor:publish', ['--tag' => 'posthog-config']);
        if ($result !== self::SUCCESS) {
            return $result;
        }
        $this->info('Set POSTHOG_API_KEY and POSTHOG_HOST in your server environment.');
        $this->info('Never expose POSTHOG_SECRET_KEY to a browser.');
        return self::SUCCESS;
    }
}
