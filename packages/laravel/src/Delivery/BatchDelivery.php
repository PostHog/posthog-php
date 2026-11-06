<?php

namespace PostHog\Laravel\Delivery;

use Closure;
use Illuminate\Contracts\Container\Container;
use PostHog\Consumer\LibCurl;
use PostHog\Laravel\PostHog;
use PostHog\StringNormalizer;

/** @internal Uses verified HTTP in workers without re-enriching or re-enqueueing events. */
class BatchDelivery extends LibCurl
{
    public function __construct(Container $app)
    {
        $config = $app['config']->get('posthog', []);
        $options = $config['sdk'] ?? [];
        $options['host'] = StringNormalizer::normalizeHost($config['host'] ?? null);
        $options['timeout'] = $options['http_timeout_ms']
            ?? (($options['consumer'] ?? null) === 'socket' ? 10000 : ($options['timeout'] ?? 10000));
        $options['verify_batch_events_request'] = true;
        $options['library_name'] = 'posthog-laravel';
        $options['library_version'] = PostHog::VERSION;
        if (isset($options['error_handler']) && is_string($options['error_handler'])) {
            $options['error_handler'] = $app->make($options['error_handler']);
        }
        if (isset($options['error_handler'])) {
            $options['error_handler'] = Closure::fromCallable($options['error_handler']);
        }
        parent::__construct(StringNormalizer::normalizeOptional($config['api_key'] ?? null) ?? '', $options);
    }

    public function deliver(string $events): void
    {
        if (trim($this->apiKey) === '') {
            throw new DeliveryFailed('PostHog queued delivery requires a project API key.');
        }
        $result = $this->flushBatch(json_decode($events, flags: JSON_THROW_ON_ERROR));
        if ($result !== true) {
            throw new DeliveryFailed(
                'PostHog batch delivery failed.',
                $result === self::FLUSH_BATCH_RETRYABLE_FAILURE,
            );
        }
    }
}
