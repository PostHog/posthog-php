<?php

namespace PostHog\Test;

use PostHog\HttpResponse;
use PostHog\Test\Assets\MockedResponses;

class MockedHttpClient extends \PostHog\HttpClient
{

    private $flagEndpointResponse;

    public function __construct(
        string $host,
        bool $useSsl = true,
        int $maximumBackoffDuration = 10000,
        bool $compressRequests = false,
        bool $debug = false,
        ?Closure $errorHandler = null,
        int $curlTimeoutMilliseconds = 750,
        array $flagEndpointResponse = []
    ) {
        parent::__construct($host, $useSsl, $maximumBackoffDuration, $compressRequests, $debug, $errorHandler, $curlTimeoutMilliseconds);
        $this->flagEndpointResponse = $flagEndpointResponse;
    }

    public function sendRequest(string $path, ?string $payload, array $extraHeaders = []): HttpResponse
    {
        if (!isset($this->calls)) {
            $this->calls = array();
        }
        array_push($this->calls, array("path" => $path, "payload" => $payload));

        if (str_starts_with($path, "/decide/")) {
            return new HttpResponse(json_encode(MockedResponses::DECIDE_REQUEST), 200);
        }

        if (str_starts_with($path, "/api/feature_flag/local_evaluation")) {
            return new HttpResponse(json_encode($this->flagEndpointResponse), 200);
        }

        return parent::sendRequest($path, $payload, $extraHeaders);
    }
}
