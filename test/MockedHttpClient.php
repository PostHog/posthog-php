<?php

namespace PostHog\Test;

use Closure;
use PostHog\HttpResponse;
use PostHog\Test\Assets\MockedResponses;

class MockedHttpClient extends \PostHog\HttpClient
{
    public $calls;

    private $flagEndpointResponse;
    private $decideEndpointResponse;

    public function __construct(
        string $host,
        bool $useSsl = true,
        int $maximumBackoffDuration = 10000,
        bool $compressRequests = false,
        bool $debug = false,
        ?Closure $errorHandler = null,
        int $curlTimeoutMilliseconds = 750,
        array $flagEndpointResponse = [],
        array $decideEndpointResponse = []
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
        $this->decideEndpointResponse = !empty($decideEndpointResponse) ? $decideEndpointResponse : MockedResponses::DECIDE_REQUEST;
    }

    public function sendRequest(string $path, ?string $payload, array $extraHeaders = [], array $requestOptions = []): HttpResponse
    {
        if (!isset($this->calls)) {
            $this->calls = [];
        }
        array_push($this->calls, array("path" => $path, "payload" => $payload, "extraHeaders" => $extraHeaders, "requestOptions" => $requestOptions));

        if (str_starts_with($path, "/decide/")) {
            return new HttpResponse(json_encode($this->decideEndpointResponse), 200);
        }

        if (str_starts_with($path, "/api/feature_flag/local_evaluation")) {
            return new HttpResponse(json_encode($this->flagEndpointResponse), 200);
        }

        return parent::sendRequest($path, $payload, $extraHeaders, $requestOptions);
    }
}
