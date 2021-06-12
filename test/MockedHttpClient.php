<?php

namespace PostHog\Test;

use PostHog\HttpResponse;
use PostHog\Test\Assets\MockedResponses;

class MockedHttpClient extends \PostHog\HttpClient
{
    private const ENDPOINTS_TO_MOCK = [
        '/api/feature_flag'
    ];

    private const ENDPOINT_MOCKED_RESPONSE_MAPPING = [
        '/api/feature_flag' => MockedResponses::SIMPLE_FLAG_EXAMPLE_REQUEST
    ];

    public function sendRequest(string $path, ?string $payload, array $extraHeaders = []): HttpResponse
    {
        if (in_array($path, self::ENDPOINTS_TO_MOCK)) {
            return new HttpResponse(
                json_encode(self::ENDPOINT_MOCKED_RESPONSE_MAPPING[$path]),
                200
            );
        }
        return parent::sendRequest($path, $payload, $extraHeaders);
    }
}
