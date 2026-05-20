<?php

namespace PostHog;

/**
 * Response wrapper returned by the SDK HTTP client.
 */
class HttpResponse
{
    private $response;
    private $responseCode;
    private $etag;
    private $curlErrno;

    /**
     * Create an HTTP response wrapper.
     *
     * @param mixed $response Raw response body or false on cURL failure.
     * @param int $responseCode HTTP status code, or 0 when no response was received.
     * @param string|null $etag ETag response header, when requested and present.
     * @param int $curlErrno cURL error number, or 0 when no cURL error occurred.
     */
    public function __construct($response, $responseCode, ?string $etag = null, int $curlErrno = 0)
    {
        $this->response = $response;
        $this->responseCode = $responseCode;
        $this->etag = $etag;
        $this->curlErrno = $curlErrno;
    }

    /**
     * Get the raw response body.
     *
     * @return mixed
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Get the HTTP response code.
     *
     * @return mixed
     */
    public function getResponseCode()
    {
        return $this->responseCode;
    }

    /**
     * Get the ETag response header, when captured.
     *
     * @return string|null
     */
    public function getEtag(): ?string
    {
        return $this->etag;
    }

    /**
     * Check if the response is a 304 Not Modified
     *
     * @return bool
     */
    public function isNotModified(): bool
    {
        return $this->responseCode === 304;
    }

    /**
     * Get the curl error number (0 if no error)
     *
     * @return int
     */
    public function getCurlErrno(): int
    {
        return $this->curlErrno;
    }
}
