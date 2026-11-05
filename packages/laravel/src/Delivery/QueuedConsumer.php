<?php

namespace PostHog\Laravel\Delivery;

use Illuminate\Contracts\Bus\Dispatcher;
use PostHog\Consumer\LibCurl;
use PostHog\Laravel\Jobs\DeliverEvents;
use Throwable;

/** @internal Hands prepared batches to Laravel instead of sending them from the producer. */
final class QueuedConsumer extends LibCurl
{
    protected $type = 'LaravelQueue';

    private bool $flushing = false;

    public function __construct(
        string $apiKey,
        array $options,
        private Dispatcher $dispatcher,
        private array $settings,
    ) {
        parent::__construct($apiKey, $options);
    }

    public function flush()
    {
        if ($this->flushing) {
            return true;
        }
        $this->flushing = true;
        try {
            return parent::flush();
        } finally {
            $this->flushing = false;
        }
    }

    public function flushBatch($messages)
    {
        $payload = $this->encodeBatchPayload($messages);
        if ($payload === false) {
            return self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
        }
        // Round-trip JSON before persistence: no credentials, closures or application objects in jobs.
        $events = json_encode(json_decode($payload, flags: JSON_THROW_ON_ERROR)->batch, JSON_THROW_ON_ERROR);
        $job = new DeliverEvents($events);
        $job->onConnection($this->settings['connection'] ?? null);
        $job->onQueue($this->settings['name'] ?? null);
        try {
            $this->dispatcher->dispatch($job);
            return true;
        } catch (Throwable) {
            $this->handleError(0, 'Failed to enqueue PostHog delivery batch.');
            return self::FLUSH_BATCH_RETRYABLE_FAILURE;
        }
    }
}
