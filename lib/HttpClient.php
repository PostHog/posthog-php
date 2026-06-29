<?php

namespace PostHog;

use Closure;

/**
 * HTTP client used by the SDK for PostHog API requests.
 *
 * @internal
 */
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
     * @var int Maximum retry backoff duration in milliseconds.
     */
    private $maximumBackoffDurationMs;

    /**
     * @var bool
     */
    private $compressRequests;

    /**
     * @var Closure|null
     */
    private $errorHandler;
    /**
     * @var bool
     */
    private $debug;

    /**
     * @var int The maximum number of milliseconds to allow cURL functions to execute / wait.
     */
    private $curlTimeoutMilliseconds;

    /**
     * Create an HTTP client.
     *
     * @param string $host PostHog host without protocol.
     * @param bool $useSsl Whether to use HTTPS.
     * @param int $maximumBackoffDuration Maximum retry backoff duration in milliseconds.
     * @param bool $compressRequests Whether to gzip request bodies.
     * @param bool $debug Whether to emit debug logs.
     * @param Closure|null $errorHandler Optional callback invoked for request errors.
     * @param int $curlTimeoutMilliseconds Default cURL timeout in milliseconds.
     */
    public function __construct(
        string $host,
        bool $useSsl = true,
        int $maximumBackoffDuration = 10000,
        bool $compressRequests = false,
        bool $debug = false,
        ?Closure $errorHandler = null,
        int $curlTimeoutMilliseconds = 10000
    ) {
        $this->host = $host;
        $this->useSsl = $useSsl;
        $this->maximumBackoffDurationMs = $maximumBackoffDuration;
        $this->compressRequests = $compressRequests;
        $this->debug = $debug;
        $this->errorHandler = $errorHandler;
        $this->curlTimeoutMilliseconds = $curlTimeoutMilliseconds;
    }

    /**
     * Send a request to the configured PostHog host.
     *
     * @param string $path Request path, including leading slash.
     * @param string|null $payload JSON request body, or null for no body.
     * @param array<int, string> $extraHeaders Additional cURL header strings.
     * @param array{
     *     shouldRetry?: bool,
     *     shouldVerify?: bool,
     *     includeEtag?: bool,
     *     timeout?: int,
     *     compressRequest?: bool
     * } $requestOptions
     * @return HttpResponse
     */
    // phpcs:ignore Generic.Files.LineLength.TooLong
    public function sendRequest(string $path, ?string $payload, array $extraHeaders = [], array $requestOptions = []): HttpResponse
    {
        $protocol = $this->useSsl ? "https://" : "http://";

        $backoff = 100; // Set initial waiting time to 100ms

        $shouldRetry = $requestOptions['shouldRetry'] ?? true;
        $shouldVerify = $requestOptions['shouldVerify'] ?? true;
        $includeEtag = $requestOptions['includeEtag'] ?? false;
        $compressRequest = $requestOptions['compressRequest'] ?? $this->compressRequests;

        do {
            // open connection
            $ch = curl_init();
            $responseHeaders = [];

            if (null !== $payload) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $headers = [];
            $headers[] = 'Content-Type: application/json';
            if ($compressRequest) {
                $headers[] = 'Content-Encoding: gzip';
            }

            // check if timeout exists in request options, if not use default
            $timeout = $this->curlTimeoutMilliseconds;
            if (isset($requestOptions['timeout'])) {
                $timeout = $requestOptions['timeout'];
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, $extraHeaders));
            curl_setopt($ch, CURLOPT_URL, $protocol . $this->host . $path);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, $shouldVerify);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $shouldVerify ? $timeout : 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $timeout);
            if (! $shouldVerify) {
                curl_setopt($ch, CURLOPT_NOSIGNAL, true);
                curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
            }

            // Capture response headers if we need to extract ETag or honor Retry-After.
            if ($includeEtag) {
                curl_setopt($ch, CURLOPT_HEADER, true);
            } else {
                curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $header) use (&$responseHeaders): int {
                    $responseHeaders[] = trim($header);
                    return strlen($header);
                });
            }

            // retry failed requests just once to diminish impact on performance
            $httpResponse = $this->executePost($ch, $includeEtag);
            $responseCode = $httpResponse->getResponseCode();

            // Handle 304 Not Modified - this is a success, not an error
            if ($responseCode === 304) {
                if ($this->debug) {
                    $maskedUrl = $this->maskTokensInUrl($protocol . $this->host . $path);
                    error_log("[PostHog][HttpClient] GET " . $maskedUrl . " returned 304 Not Modified");
                }
                break;
            }

            if ($shouldVerify && 200 != $responseCode) {
                // log error
                $this->handleError($ch, $responseCode);

                if ($shouldRetry === false) {
                    break;
                } elseif ($this->isRetryableStatus($responseCode)) {
                    // Retry transient failures. Honor Retry-After when provided, otherwise use
                    // exponential backoff starting at 100ms.
                    $retryAfterMs = $this->retryAfterMilliseconds($responseHeaders);
                    usleep(($retryAfterMs ?? $backoff) * 1000);
                    $backoff *= 2;
                } else {
                    // Do not retry non-transient responses (e.g. 400, 401, 403, 413,
                    // or responseCode 0 for network errors). PHP sends synchronously in the hosting
                    // app's request path, so broad retries would slow down the host application.
                    break;
                }
            } else {
                break;  // no error
            }
        } while ($shouldRetry && $backoff < $this->maximumBackoffDurationMs);

        return $httpResponse;
    }

    private function executePost($ch, bool $includeEtag = false): HttpResponse
    {
        $response = curl_exec($ch);
        $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErrno = curl_errno($ch);
        $etag = null;

        if ($includeEtag && $response !== false) {
            // Parse headers to extract ETag
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);

            // Extract ETag from headers (case-insensitive)
            if (preg_match('/^etag:\s*(.+)$/mi', $headers, $matches)) {
                $etag = trim($matches[1]);
            }

            return new HttpResponse($body, $responseCode, $etag, $curlErrno);
        }

        return new HttpResponse($response, $responseCode, null, $curlErrno);
    }

    private function isRetryableStatus(int $responseCode): bool
    {
        return $responseCode === 408
            || $responseCode === 429
            || ($responseCode >= 500 && $responseCode <= 600);
    }

    /**
     * @param array<int, string> $headers
     */
    private function retryAfterMilliseconds(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Retry-After:') !== 0) {
                continue;
            }

            $value = trim(substr($header, strlen('Retry-After:')));
            if ($value === '') {
                return null;
            }

            if (ctype_digit($value)) {
                return max(0, (int) $value * 1000);
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return max(0, (int) (($timestamp - time()) * 1000));
            }
        }

        return null;
    }

    private function handleError($code, $message)
    {
        if (null !== $this->errorHandler) {
            $handler = $this->errorHandler;
            $handler($code, $message);
        }

        if ($this->debug) {
            error_log("[PostHog][HttpClient] " . $message);
        }
    }

    /**
     * Mask tokens in URLs to avoid exposing them in logs
     *
     * @param string $url
     * @return string
     */
    public function maskTokensInUrl(string $url): string
    {
        return preg_replace('/token=[^&]+/', 'token=[REDACTED]', $url);
    }
}
