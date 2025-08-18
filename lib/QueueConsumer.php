<?php

namespace PostHog;

abstract class QueueConsumer extends Consumer
{
    protected $type = "QueueConsumer";

    protected $queue;
    protected $failed_queue = array();
    protected $max_queue_size = 1000;
    protected $batch_size = 100;
    protected $maximum_backoff_duration = 10000;    // Set maximum waiting limit to 10s
    protected $max_retry_attempts = 3;
    protected $max_failed_queue_size = 1000;
    protected $initial_retry_delay = 60;            // Initial retry delay in seconds
    protected $host = "app.posthog.com";
    protected $compress_request = false;

    /**
     * Store our api key and options as part of this consumer
     * @param string $apiKey
     * @param array $options
     */
    public function __construct($apiKey, $options = array())
    {
        parent::__construct($apiKey, $options);

        if (isset($options["max_queue_size"])) {
            $this->max_queue_size = $options["max_queue_size"];
        }

        if (isset($options["batch_size"])) {
            $this->batch_size = $options["batch_size"];
        }

        if (isset($options["maximum_backoff_duration"])) {
            $this->maximum_backoff_duration = (int) $options["maximum_backoff_duration"];
        }

        if (isset($options["max_retry_attempts"])) {
            $this->max_retry_attempts = (int) $options["max_retry_attempts"];
        }

        if (isset($options["max_failed_queue_size"])) {
            $this->max_failed_queue_size = (int) $options["max_failed_queue_size"];
        }

        if (isset($options["initial_retry_delay"])) {
            $this->initial_retry_delay = (int) $options["initial_retry_delay"];
        }

        if (isset($options["host"])) {
            $this->host = $options["host"];

            if ($this->host && preg_match("/^https?:\\/\\//i", $this->host)) {
                $this->options['ssl'] = substr($this->host, 0, 5) == 'https';
                $this->host = preg_replace("/^https?:\\/\\//i", "", $this->host);
            }
        }

        if (isset($options["compress_request"])) {
            $this->compress_request = json_decode($options["compress_request"]);
        }

        $this->queue = array();
        $this->failed_queue = array();
    }

    public function __destruct()
    {
        // Flush our queue on destruction
        $this->flush();
    }

    /**
     * Captures a user action
     *
     * @param array $message
     * @return boolean whether the capture call succeeded
     */
    public function capture(array $message)
    {
        return $this->enqueue($message);
    }

    /**
     * Tags properties about the user.
     *
     * @param array $message
     * @return boolean whether the identify call succeeded
     */
    public function identify(array $message)
    {
        return $this->enqueue($message);
    }

    /**
     * Aliases from one user id to another
     *
     * @param array $message
     * @return boolean whether the alias call succeeded
     */
    public function alias(array $message)
    {
        return $this->enqueue($message);
    }

    /**
     * Flushes our queue of messages by batching them to the server
     */
    public function flush(): bool
    {
        // First, try to retry any failed batches
        $this->retryFailedBatches();

        // If no new messages, we're done
        if (empty($this->queue)) {
            return true;
        }

        // Process messages batch by batch, maintaining transactional behavior
        $overallSuccess = true;
        $initialQueueSize = count($this->queue);

        while (!empty($this->queue)) {
            $queueSizeBefore = count($this->queue);
            $batchSize = min($this->batch_size, $queueSizeBefore);
            $batch = array_slice($this->queue, 0, $batchSize);
            
            if ($this->flushBatchWithRetry($batch)) {
                // Success: remove these messages from queue
                $this->queue = array_slice($this->queue, $batchSize);
            } else {
                // Failed: move to failed queue and remove from main queue
                $this->addToFailedQueue($batch);
                $this->queue = array_slice($this->queue, $batchSize);
                $overallSuccess = false;
            }

            // Safety check: ensure queue size is actually decreasing
            $queueSizeAfter = count($this->queue);
            if ($queueSizeAfter >= $queueSizeBefore) {
                // This should never happen, but prevents infinite loops
                $this->handleError('flush_safety_break', 
                    sprintf('Queue size not decreasing: before=%d, after=%d. Breaking to prevent infinite loop.', 
                        $queueSizeBefore, $queueSizeAfter));
                break;
            }
        }

        return $overallSuccess;
    }

    /**
     * Flush a batch with immediate retry logic
     */
    protected function flushBatchWithRetry(array $batch): bool
    {
        $backoff = 100; // Start with 100ms

        for ($attempt = 0; $attempt < $this->max_retry_attempts; $attempt++) {
            if ($attempt > 0) {
                usleep($backoff * 1000); // Wait with exponential backoff
                $backoff = min($backoff * 2, $this->maximum_backoff_duration);
            }

            if ($this->flushBatch($batch)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add batch to failed queue for later retry
     */
    protected function addToFailedQueue(array $batch): void
    {
        // Prevent memory issues by limiting failed queue size
        if (count($this->failed_queue) >= $this->max_failed_queue_size) {
            array_shift($this->failed_queue); // Remove oldest
            $this->handleError('failed_queue_overflow', 
                'Failed queue size limit reached. Dropping oldest failed batch.');
        }

        $this->failed_queue[] = [
            'messages' => $batch,
            'attempts' => 0,
            'next_retry' => time() + $this->initial_retry_delay,
            'created_at' => time()
        ];
    }

    /**
     * Retry failed batches that are ready for retry
     */
    protected function retryFailedBatches(): void
    {
        if (empty($this->failed_queue)) {
            return;
        }

        $currentTime = time();
        $remainingFailed = [];

        foreach ($this->failed_queue as $failedBatch) {
            if (!$this->isReadyForRetry($failedBatch, $currentTime)) {
                $remainingFailed[] = $failedBatch;
                continue;
            }

            if ($this->retryFailedBatch($failedBatch)) {
                // Success - don't add back to queue
                continue;
            }

            // Still failed - update for next retry or mark as permanent failure
            $updatedBatch = $this->updateFailedBatch($failedBatch, $currentTime);
            if ($updatedBatch !== null) {
                $remainingFailed[] = $updatedBatch;
            }
        }

        $this->failed_queue = $remainingFailed;
    }

    /**
     * Check if a failed batch is ready for retry
     */
    private function isReadyForRetry(array $failedBatch, int $currentTime): bool
    {
        return $failedBatch['next_retry'] <= $currentTime && 
               $failedBatch['attempts'] < $this->max_retry_attempts;
    }

    /**
     * Attempt to retry a single failed batch
     */
    private function retryFailedBatch(array $failedBatch): bool
    {
        if ($this->flushBatch($failedBatch['messages'])) {
            $this->handleError('batch_retry_success', 
                sprintf('Successfully retried batch after %d failed attempts', $failedBatch['attempts']));
            return true;
        }
        return false;
    }

    /**
     * Update failed batch for next retry or mark as permanently failed
     * @return array|null Updated batch or null if permanently failed
     */
    private function updateFailedBatch(array $failedBatch, int $currentTime): ?array
    {
        $failedBatch['attempts']++;
        
        if ($failedBatch['attempts'] >= $this->max_retry_attempts) {
            // Permanently failed
            $this->handleError('batch_permanently_failed', 
                sprintf('Batch permanently failed after %d attempts, %d messages lost', 
                    $this->max_retry_attempts, count($failedBatch['messages'])));
            return null;
        }

        // Calculate next retry time with exponential backoff (capped at 1 hour)
        $backoffMinutes = min(pow(2, $failedBatch['attempts']), 60);
        $failedBatch['next_retry'] = $currentTime + ($backoffMinutes * 60);
        
        return $failedBatch;
    }

    /**
     * Adds an item to our queue.
     * @param mixed $item
     * @return boolean whether call has succeeded
     */
    public function enqueue($item)
    {
        $count = count($this->queue);

        if ($count > $this->max_queue_size) {
            return false;
        }

        $count = array_push($this->queue, $item);

        if ($count >= $this->batch_size) {
            return $this->flush(); // return ->flush() result: true on success
        }

        return true;
    }

    /**
     * Given a batch of messages the method returns
     * a valid payload.
     *
     * @param {Array} $batch
     * @return {Array}
     */
    protected function payload($batch)
    {
        return array(
            "batch" => $batch,
            "api_key" => $this->apiKey,
        );
    }

    /**
     * Get statistics about failed queue for observability
     */
    public function getFailedQueueStats(): array
    {
        $totalMessages = 0;
        $oldestRetry = null;
        $attemptCounts = [];

        foreach ($this->failed_queue as $failedBatch) {
            $totalMessages += count($failedBatch['messages']);
            
            if ($oldestRetry === null || $failedBatch['next_retry'] < $oldestRetry) {
                $oldestRetry = $failedBatch['next_retry'];
            }

            $attempts = $failedBatch['attempts'];
            $attemptCounts[$attempts] = ($attemptCounts[$attempts] ?? 0) + 1;
        }

        return [
            'failed_batches' => count($this->failed_queue),
            'total_failed_messages' => $totalMessages,
            'oldest_retry_time' => $oldestRetry,
            'attempt_distribution' => $attemptCounts,
            'current_queue_size' => count($this->queue),
            'max_failed_queue_size' => $this->max_failed_queue_size,
        ];
    }

    /**
     * Clear all failed queues (useful for testing or manual recovery)
     */
    public function clearFailedQueue(): int
    {
        $clearedCount = count($this->failed_queue);
        $this->failed_queue = [];
        return $clearedCount;
    }
}
