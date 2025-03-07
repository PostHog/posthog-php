<?php

namespace PostHog;

abstract class QueueConsumer extends Consumer
{
    protected $type = "QueueConsumer";

    protected $queue;
    protected $max_queue_size = 1000;
    protected $batch_size = 100;
    protected $maximum_backoff_duration = 10000;    // Set maximum waiting limit to 10s
    protected $host = "app.posthog.com";
    protected $compress_request = false;
    protected $async = false;

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

        if (isset($options["async"])) {
            $this->async = (bool) $options["async"];
        }

        $this->queue = array();
    }

    public function __destruct()
    {
        // Flush our queue on destruction
        if ($this->async) {
            $this->flushAsync();
        } else {
            $this->flush();
        }
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
     * Flushes our queue of messages by forking a new process.
     */
    public function flushAsync()
    {
        if (empty($this->queue)) {
            return;
        }

        // Fall back to sync flush if pcntl is not available
        if (!function_exists('pcntl_fork')) {
            error_log("pcntl_fork not available, falling back to sync flush");
            return $this->flush();
        }

        // Copy and clear the queue
        $queue = $this->queue;
        $this->queue = array();
        $pid = pcntl_fork();

        if ($pid == -1) {
            error_log("pcntl_fork failed, falling back to sync flush");
            $this->queue = $queue;
            return $this->flush();
        } elseif ($pid) {
            // Parent process
            return true;
        } else {
            // Child process
            // Detach from parent process
            if (function_exists('posix_setsid')) {
                posix_setsid();
            }

            // Process the queue
            $count = count($queue);

            while ($count > 0) {
                $batch = array_splice($queue, 0, min($this->batch_size, $count));
                $this->flushBatch($batch);
                $count = count($queue);
            }

            // Child process must exit
            exit(0);
        }
    }

    /**
     * Flushes our queue of messages by batching them to the server
     */
    public function flush()
    {
        $count = count($this->queue);
        $success = true;

        while ($count > 0 && $success) {
            $batch = array_splice($this->queue, 0, min($this->batch_size, $count));
            $success = $this->flushBatch($batch);

            $count = count($this->queue);
        }

        return $success;
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
            if ($this->async) {
                return $this->flushAsync();
            }
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
}
