<?php

namespace PostHog\Laravel\Delivery;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use JsonException;
use PostHog\Consumer\LibCurl;
use PostHog\Laravel\Jobs\DeliverEvents;
use Throwable;

/** @internal Hands prepared batches to Laravel instead of sending them from the producer. */
final class QueuedConsumer extends LibCurl
{
    protected $type = 'LaravelQueue';

    private bool $flushing = false;
    private int $deferredEvents = 0;

    public function __construct(
        string $apiKey,
        array $options,
        private Dispatcher $dispatcher,
        private array $settings,
        private Container $app,
    ) {
        parent::__construct($apiKey, $options);
    }

    public function enqueue($item)
    {
        if ($this->deferredEvents > 0 && count($this->queue) + $this->deferredEvents >= $this->max_queue_size) {
            return false;
        }
        return parent::enqueue($item);
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
        try {
            $events = json_encode(json_decode($payload, flags: JSON_THROW_ON_ERROR)->batch, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->handleError($exception->getCode(), 'Failed to normalize PostHog delivery batch.');
            return self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
        }
        $job = new DeliverEvents($events);
        $job->onConnection($this->settings['connection'] ?? null);
        $job->onQueue($this->settings['name'] ?? null);
        $connection = $job->connection ?: $this->app['config']->get('queue.default');
        if (
            $this->app['config']->get('queue.connections.' . $connection . '.after_commit', false)
            && $this->app->bound('db.transactions')
            && $this->app['db.transactions']->callbackApplicableTransactions()->isNotEmpty()
        ) {
            $count = count($messages);
            $this->deferredEvents += $count;
            $capacity = new DeferredCapacity(function (bool $discarded) use ($count, $job): void {
                $this->deferredEvents -= $count;
                if ($discarded) {
                    $this->queue = array_merge($this->queue, json_decode($job->events, flags: JSON_THROW_ON_ERROR));
                }
            });
            $this->app['db.transactions']->addCallback(function () use ($job, $capacity): void {
                try {
                    try {
                        if ($this->dispatch($job->beforeCommit())) {
                            return;
                        }
                    } catch (Throwable) {
                        // A diagnostic callback must not fail an already-committed application transaction.
                    }
                    $this->queue = array_merge($this->queue, json_decode($job->events, flags: JSON_THROW_ON_ERROR));
                } finally {
                    $capacity->release();
                }
            });
            return true;
        }
        return $this->dispatch($job) ? true : self::FLUSH_BATCH_RETRYABLE_FAILURE;
    }

    private function dispatch(DeliverEvents $job): bool
    {
        try {
            $this->dispatcher->dispatch($job);
            return true;
        } catch (Throwable) {
            $this->handleError(0, 'Failed to enqueue PostHog delivery batch.');
            return false;
        }
    }
}
