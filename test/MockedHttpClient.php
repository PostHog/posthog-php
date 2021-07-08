<?php

namespace PostHog\Test;

use PostHog\HttpResponse;
use PostHog\Test\Assets\MockedResponses;

class MockedHttpClient extends \PostHog\HttpClient
{
    public function sendRequest(string $path, ?string $payload, array $extraHeaders = []): HttpResponse
    {
        return parent::sendRequest($path, $payload, $extraHeaders);
    }
}
