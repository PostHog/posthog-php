<?php

namespace PostHog;

class HttpClient
{
    /**
     * @var string
     */
    private $host;

    /**
     * @var bool
     */
    private $useSsl;
    /**
     * @var int
     */
    private $maximumBackoffDuration;

    public function __construct(string $host, bool $useSsl = true, int $maximumBackoffDuration = 10000)
    {
        $this->host = $host;
        $this->useSsl = $useSsl;
        $this->maximumBackoffDuration = $maximumBackoffDuration;
    }

    /**
     * @param string $path
     * @param string|null $payload
     * @param array $extraHeaders
     * @return mixed
     */
    public function sendRequest(string $path, string $payload, array $extraHeaders = [])
    {
        $protocol = $this->useSsl ? "https://" : "http://";

        $backoff = 100;     // Set initial waiting time to 100ms

        while ($backoff < $this->maximumBackoffDuration) {
            // open connection
            $ch = curl_init();


            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);


            $headers = [];
            $headers[] = 'Content-Type: application/json';
            if ($this->compress_request) {
                $headers[] = 'Content-Encoding: gzip';
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, $extraHeaders));
            curl_setopt($ch, CURLOPT_URL, $protocol . $this->host . $path);
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

    private function executePost($ch): array
    {
        return [
            curl_exec($ch),
            curl_getinfo($ch, CURLINFO_RESPONSE_CODE)
        ];
    }
}
