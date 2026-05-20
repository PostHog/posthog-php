<?php

namespace PostHog\Consumer;

use PostHog\HttpClient;
use PostHog\QueueConsumer;

/**
 * Queue consumer that sends batches using libcurl.
 *
 * @internal
 */
class LibCurl extends QueueConsumer
{
    protected $type = "LibCurl";
    /**
     * @var HttpClient
     */
    private $httpClient;

    /**
     * Creates a new queued libcurl consumer
     * @param string $apiKey Project API key.
     * @param array<string, mixed> $options Consumer options.
     * @param HttpClient|null $httpClient Custom HTTP client, primarily for tests.
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
     * @param array<int, array<string, mixed>> $messages Array of messages to send.
     * @return bool Whether the request succeeded.
     */
    public function flushBatch($messages)
    {
        $body = $this->payload($messages);
        $payload = json_encode($body);

        if (strlen($payload) >= self::MAX_BATCH_PAYLOAD_SIZE) {
            if ($this->debug()) {
                $msg = "Message size is larger than " . self::MAX_BATCH_PAYLOAD_SIZE_HUMAN;
                error_log("[PostHog][" . $this->type . "] " . $msg);
            }

            return false;
        }

        if ($this->compress_request) {
            $payload = gzencode($payload);
        }

        $shouldVerify = $this->options['verify_batch_events_request'] ?? true;
        $response = $this->httpClient->sendRequest(
            '/batch/',
            $payload,
            [
                // Send user agent in the form of {library_name}/{library_version} as per RFC 7231.
                "User-Agent: {$messages[0]['library']}/{$messages[0]['library_version']}",
            ],
            [
                'shouldVerify' => $shouldVerify,
            ]
        );

        if (!$shouldVerify) {
            return $response->getResponse() !== false;
        }

        // Keep batch success semantics aligned with HttpClient retry handling and the Socket consumer.
        return $response->getResponseCode() === 200;
    }
}
