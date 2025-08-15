<?php

namespace PostHog\Consumer;

use PostHog\HttpClient;
use PostHog\QueueConsumer;

class LibCurl extends QueueConsumer
{
    protected $type = "LibCurl";
    /**
     * @var HttpClient
     */
    private $httpClient;

    /**
     * Creates a new queued libcurl consumer
     * @param string $apiKey
     * @param array $options
     *     boolean  "debug" - whether to use debug output, wait for response.
     *     number   "max_queue_size" - the max size of messages to enqueue
     *     number   "batch_size" - how many messages to send in a single request
     */
    public function __construct($apiKey, $options = [], ?HttpClient $httpClient = null)
    {
        parent::__construct($apiKey, $options);
        $this->httpClient = $httpClient !== null ? $httpClient : new HttpClient(
            $this->host,
            $this->ssl(),
            $this->maximum_backoff_duration,
            $this->compress_request,
            $this->debug(),
            $this->options['error_handler'] ?? null
        );
    }

    /**
     * Define getter method for consumer type
     *
     * @return string
     */
    public function getConsumer()
    {
        return $this->type;
    }

    /**
     * Make a sync request to our API. If debug is
     * enabled, we wait for the response
     * and retry once to diminish impact on performance.
     * @param array $messages array of all the messages to send
     * @return boolean whether the request succeeded
     */
    public function flushBatch($messages)
    {
        if (empty($messages)) {
            return true;
        }

        return $this->sendBatch($messages);
    }

    /**
     * Sends a batch of messages, splitting if necessary to fit 32KB limit
     * @param array $messages
     * @return boolean success
     */
    private function sendBatch($messages)
    {
        $body = $this->payload($messages);
        $payload = json_encode($body);

        // Check 32KB limit
        if (strlen($payload) >= 32 * 1024) {
            return $this->handleOversizedBatch($messages, strlen($payload));
        }

        // Send the batch
        return $this->performHttpRequest($payload, $messages[0]);
    }

    /**
     * Handles batches that exceed 32KB limit
     * @param array $messages
     * @param int $payloadSize
     * @return boolean success
     */
    private function handleOversizedBatch($messages, $payloadSize)
    {
        $messageCount = count($messages);
        
        // Single message too large - drop it
        if ($messageCount === 1) {
            $this->handleError(
                'payload_too_large',
                sprintf(
                    'Single message payload size (%d bytes) exceeds 32KB limit. Message will be dropped.',
                    $payloadSize
                )
            );
            return false;
        }

        // Split and try both halves
        $midpoint = intval($messageCount / 2);
        $firstHalf = array_slice($messages, 0, $midpoint);
        $secondHalf = array_slice($messages, $midpoint);

        $firstResult = $this->sendBatch($firstHalf);
        $secondResult = $this->sendBatch($secondHalf);

        return $firstResult && $secondResult;
    }

    /**
     * Performs the actual HTTP request
     * @param string $payload
     * @param array $sampleMessage
     * @return boolean success
     */
    private function performHttpRequest($payload, $sampleMessage)
    {
        if ($this->compress_request) {
            $payload = gzencode($payload);
        }

        $response = $this->httpClient->sendRequest(
            '/batch/',
            $payload,
            [
                "User-Agent: {$sampleMessage['library']}/{$sampleMessage['library_version']}",
            ],
            [
                'shouldVerify' => $this->options['verify_batch_events_request'] ?? true,
            ]
        );

        // Return boolean based on whether we got a response
        return !empty($response->getResponse());
    }

}
