<?php

namespace PostHog\Laravel\Commands;

use Illuminate\Console\Command;
use PostHog\Laravel\PostHog;

class DoctorCommand extends Command
{
    protected $signature = 'posthog:doctor';
    protected $description = 'Check server-side PostHog configuration without sending traffic';

    public function handle(PostHog $posthog): int
    {
        if (!$posthog->enabled()) {
            $this->warn('PostHog is disabled or has no project API key. No traffic will be sent.');
            return self::FAILURE;
        }
        $host = config('posthog.host');
        if (
            !is_string($host) || !filter_var($host, FILTER_VALIDATE_URL)
            || !in_array(parse_url($host, PHP_URL_SCHEME), ['http', 'https'], true)
        ) {
            $this->error('PostHog host must be an HTTP or HTTPS URL.');
            return self::FAILURE;
        }
        $this->info('PostHog is configured. Credentials are server-side. No network check was performed.');
        return self::SUCCESS;
    }
}
