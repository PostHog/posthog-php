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
     * @return bool|string Whether the request succeeded or a queue failure classification.
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

            return self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
        }

        $isCompressed = false;
        if ($this->compress_request) {
            $compressedPayload = gzencode($payload);

            if (false !== $compressedPayload) {
                $payload = $compressedPayload;
                $isCompressed = true;
            } else {
                $this->handleError(0, "Failed to gzip batch payload; sending uncompressed.");
            }
        }

        $shouldVerify = $this->options['verify_batch_events_request'] ?? true;
        $requestOptions = [
            'shouldVerify' => $shouldVerify,
        ];
        if ($this->compress_request) {
            $requestOptions['compressRequest'] = $isCompressed;
        }

        $response = $this->httpClient->sendRequest(
            '/batch/',
            $payload,
            [
                // Send user agent in the form of {library_name}/{library_version} as per RFC 7231.
                "User-Agent: {$this->userAgent()}",
            ],
            $requestOptions
        );

        if (!$shouldVerify) {
            if ($response->getResponse() !== false) {
                return true;
            }

            return $this->isNetworkFailure($response)
                ? self::FLUSH_BATCH_RETRYABLE_FAILURE
                : self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
        }

        // Keep batch success semantics aligned with HttpClient retry handling and the Socket consumer.
        if ($response->getResponseCode() === 200) {
            return true;
        }

        return $this->isNetworkFailure($response)
            ? self::FLUSH_BATCH_RETRYABLE_FAILURE
            : self::FLUSH_BATCH_NON_RETRYABLE_FAILURE;
    }

    private function isNetworkFailure($response)
    {
        return $response->getResponseCode() === 0 && $response->getCurlErrno() !== 0;
    }
}
