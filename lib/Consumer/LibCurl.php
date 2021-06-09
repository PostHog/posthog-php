<?php

namespace PostHog\Consumer;

use PostHog\QueueConsumer;

class LibCurl extends QueueConsumer
{
    protected $type = "LibCurl";

    /**
     * Creates a new queued libcurl consumer
     * @param string $apiKey
     * @param array $options
     *     boolean  "debug" - whether to use debug output, wait for response.
     *     number   "max_queue_size" - the max size of messages to enqueue
     *     number   "batch_size" - how many messages to send in a single request
     */
    public function __construct($apiKey, $options = array())
    {
        parent::__construct($apiKey, $options);
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
        $body = $this->payload($messages);
        $payload = json_encode($body);

        // Verify message size is below than 32KB
        if (strlen($payload) >= 32 * 1024) {
            if ($this->debug()) {
                $msg = "Message size is larger than 32KB";
                error_log("[PostHog][" . $this->type . "] " . $msg);
            }

            return false;
        }

        if ($this->compress_request) {
            $payload = gzencode($payload);
        }

        return $this->sendRequest(
            '/batch/',
            $payload,
            [
                "User-Agent: {$messages['library']}/{$messages['library_version']}",
            ]
        );
    }

    public function decide(string $distinctId)
    {
        $payload = json_encode([
            'api_key' => $this->apiKey,
            'distinct_id' => $distinctId,
        ]);

        if ($this->compress_request) {
            $payload = gzencode($payload);
        }

        return $this->sendRequest('/decide/', $payload);
    }

    /**
     * @param string $path
     * @param string|null $payload
     * @param array $extraHeaders
     * @return mixed
     */
    public function sendRequest(string $path, string $payload, array $extraHeaders = [])
    {
        $protocol = $this->ssl() ? "https://" : "http://";
        $host = $this->host ?? "t.posthog.com";

        $backoff = 100;     // Set initial waiting time to 100ms

        while ($backoff < $this->maximum_backoff_duration) {
            // open connection
            $ch = curl_init();


            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);


            $headers = [];
            $headers[] = 'Content-Type: application/json';
            if ($this->compress_request) {
                $headers[] = 'Content-Encoding: gzip';
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, $extraHeaders));
            curl_setopt($ch, CURLOPT_URL, $protocol . $host . $path);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // retry failed requests just once to diminish impact on performance
            [$httpResponse, $httpResponseCode] = $this->executePost($ch);

            //close connection
            curl_close($ch);

            if (200 != $httpResponseCode) {
                // log error
                $this->handleError($ch, $httpResponseCode);

                if (($httpResponseCode >= 500 && $httpResponseCode <= 600) || 429 == $httpResponseCode) {
                    // If status code is greater than 500 and less than 600, it indicates server error
                    // Error code 429 indicates rate limited.
                    // Retry uploading in these cases.
                    usleep($backoff * 1000);
                    $backoff *= 2;
                } elseif ($httpResponseCode >= 400) {
                    break;
                } elseif ($httpResponseCode == 0) {
                    break;
                }
            } else {
                break;  // no error
            }
        }

        return $httpResponse;
    }

    public function executePost($ch): array
    {
        return [
            curl_exec($ch),
            curl_getinfo($ch, CURLINFO_RESPONSE_CODE)
        ];
    }
}
