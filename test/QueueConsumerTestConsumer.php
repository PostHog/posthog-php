<?php

namespace PostHog\Test;

use PostHog\QueueConsumer;

class QueueConsumerTestConsumer extends QueueConsumer
{
    public array $flushedBatches = [];

    private array $flushResults;

    public function __construct(array $flushResults, array $options = [])
    {
        $this->flushResults = $flushResults;
        parent::__construct('test-key', $options);
    }

    public function __destruct()
    {
        // Avoid destructor-triggered flushes changing assertions in tests.
    }

    public static function retryableFailure()
    {
        return self::FLUSH_BATCH_RETRYABLE_FAILURE;
    }

    public static function nonRetryableFailure()
    {
        return self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
    }

    public function queuedItems(): array
    {
        return $this->queue;
    }

    public function flushBatch($batch)
    {
        $this->flushedBatches[] = $batch;

        if ([] === $this->flushResults) {
            return true;
        }

        return array_shift($this->flushResults);
    }
}
