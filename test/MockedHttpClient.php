<?php

namespace PostHog\Test;

use Closure;
use PostHog\HttpResponse;
use PostHog\Test\Assets\MockedResponses;

class MockedHttpClient extends \PostHog\HttpClient
{
    public $calls;

    private $flagEndpointResponse;
    private $flagsEndpointResponse;
    private $batchResponse;
    private $batchResponses = [];

    public function __construct(
        string $host,
        bool $useSsl = true,
        int $maximumBackoffDuration = 10000,
        bool $compressRequests = false,
        bool $debug = false,
        ?Closure $errorHandler = null,
        int $curlTimeoutMilliseconds = 750,
        array $flagEndpointResponse = [],
        array $flagsEndpointResponse = []
    ) {
        parent::__construct(
            $host,
            $useSsl,
            $maximumBackoffDuration,
            $compressRequests,
            $debug,
            $errorHandler,
            $curlTimeoutMilliseconds
        );
        $this->flagEndpointResponse = $flagEndpointResponse;
        $this->flagsEndpointResponse = !empty($flagsEndpointResponse) ? $flagsEndpointResponse : MockedResponses::FLAGS_REQUEST;
    }

    public function sendRequest(string $path, ?string $payload, array $extraHeaders = [], array $requestOptions = []): HttpResponse
    {
        if (!isset($this->calls)) {
            $this->calls = [];
        }
        array_push($this->calls, array("path" => $path, "payload" => $payload, "extraHeaders" => $extraHeaders, "requestOptions" => $requestOptions));

        if (str_starts_with($path, "/flags/")) {
            return new HttpResponse(json_encode($this->flagsEndpointResponse), 200);
        }

        if (str_starts_with($path, "/api/feature_flag/local_evaluation")) {
            return new HttpResponse(json_encode($this->flagEndpointResponse), 200);
        }

        if (str_starts_with($path, "/batch/")) {
            // Use configured response if available
            if (!empty($this->batchResponses)) {
                $response = array_shift($this->batchResponses);
                return new HttpResponse($response[1], $response[0]);
            }
            
            if ($this->batchResponse !== null) {
                return new HttpResponse($this->batchResponse[1], $this->batchResponse[0]);
            }
            
            return new HttpResponse('{"status":"Ok"}', 200);
        }

        return parent::sendRequest($path, $payload, $extraHeaders, $requestOptions);
    }

    /**
     * Set a single response for batch requests
     * @param int $statusCode
     * @param string $body
     */
    public function setResponse(int $statusCode, string $body): void
    {
        $this->batchResponse = [$statusCode, $body];
    }

    /**
     * Set multiple responses for batch requests (used in sequence)
     * @param array $responses Array of [statusCode, body] pairs
     */
    public function setResponses(array $responses): void
    {
        $this->batchResponses = $responses;
    }

    /**
     * Reset all configured responses
     */
    public function resetResponses(): void
    {
        $this->batchResponse = null;
        $this->batchResponses = [];
    }
}
