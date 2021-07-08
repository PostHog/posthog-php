<?php

namespace PostHog;

class HttpResponse
{
    private $response;
    private $responseCode;

    public function __construct($response, $responseCode)
    {
        $this->response = $response;
        $this->responseCode = $responseCode;
    }

    /**
     * @return mixed
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @return mixed
     */
    public function getResponseCode()
    {
        return $this->responseCode;
    }
}
