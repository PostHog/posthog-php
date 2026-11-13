<?php

namespace PostHog\Laravel\Commands;

use Illuminate\Console\Command;
use PostHog\Laravel\PostHog;
use Throwable;

/** @internal */
final class DoctorCommand extends Command
{
    protected $signature = 'posthog:doctor';
    protected $description = 'Check server-side PostHog configuration without sending traffic';

    public function handle(PostHog $posthog): int
    {
        if (!$posthog->enabled()) {
            $this->warn('PostHog is disabled or has no project token. No traffic will be sent.');
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
        try {
            $posthog->client();
        } catch (Throwable $exception) {
            $this->error('PostHog client initialization failed (' . $exception::class . '). '
                . 'Check posthog.sdk options, including consumer, callbacks and flag_definition_cache_provider.');
            return self::FAILURE;
        }
        if (
            config('posthog.secret_key') && !config('posthog.flag_cache.enabled')
            && !config('posthog.sdk.flag_definition_cache_provider')
        ) {
            $this->warn('Local flag definitions will be fetched on first evaluation in each request or job. '
                . 'Enable posthog.flag_cache or configure a flag_definition_cache_provider to share definitions.');
        }
        $this->info('PostHog is configured. Credentials are server-side. No network check was performed.');
        return self::SUCCESS;
    }
}
