<?php

namespace PostHog\Test;

use PostHog\HttpResponse;
use PostHog\Test\Assets\MockedResponses;

class MockedHttpClient extends \PostHog\HttpClient
{
    public function sendRequest(string $path, ?string $payload, array $extraHeaders = []): HttpResponse
    {
        if (!isset($this->calls)) {
            $this->calls = array();
        }
        array_push($this->calls, array("path" => $path, "payload" => $payload));

        return parent::sendRequest($path, $payload, $extraHeaders);
    }
}
