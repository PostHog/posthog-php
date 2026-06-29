<?php

// phpcs:ignoreFile -- Compliance adapter is an executable test harness shim.
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PostHog\Client;
use PostHog\HttpClient;
use PostHog\HttpResponse;
use PostHog\PostHog;
use PostHog\Uuid;

final class RequestInfo
{
    public function __construct(
        public int $timestampMs,
        public int $statusCode,
        public int $retryAttempt,
        public int $eventCount,
        public array $uuidList,
    ) {
    }

    public function toArray(): array
    {
        return [
            'timestamp_ms' => $this->timestampMs,
            'status_code' => $this->statusCode,
            'retry_attempt' => $this->retryAttempt,
            'event_count' => $this->eventCount,
            'uuid_list' => $this->uuidList,
        ];
    }
}

final class AdapterState
{
    public ?Client $client = null;
    public int $totalEventsCaptured = 0;
    public int $totalEventsSent = 0;
    public int $totalRetries = 0;
    public ?string $lastError = null;
    /** @var list<RequestInfo> */
    public array $requestsMade = [];
    public int $pendingEvents = 0;

    public function reset(): void
    {
        if ($this->client !== null) {
            $this->discardQueuedEvents($this->client);
            try {
                $this->client->shutdown();
            } catch (Throwable $e) {
                error_log('[adapter] error shutting down client: ' . $e->getMessage());
            }
        }

        $this->client = null;
        $this->totalEventsCaptured = 0;
        $this->totalEventsSent = 0;
        $this->totalRetries = 0;
        $this->lastError = null;
        $this->requestsMade = [];
        $this->pendingEvents = 0;
    }

    public function recordCaptured(): void
    {
        $this->totalEventsCaptured++;
        $this->pendingEvents++;
    }

    public function recordRequest(int $statusCode, int $retryAttempt, int $eventCount, array $uuidList): void
    {
        $this->requestsMade[] = new RequestInfo(
            (int) floor(microtime(true) * 1000),
            $statusCode,
            $retryAttempt,
            $eventCount,
            $uuidList,
        );

        if ($retryAttempt > 0) {
            $this->totalRetries++;
        }

        if ($statusCode === 200) {
            $this->totalEventsSent += $eventCount;
            $this->pendingEvents = max(0, $this->pendingEvents - $eventCount);
        }
    }

    public function recordError(string $error): void
    {
        $this->lastError = $error;
    }

    private function discardQueuedEvents(Client $client): void
    {
        try {
            $clientReflection = new ReflectionObject($client);
            $consumerProperty = $clientReflection->getProperty('consumer');
            $consumerProperty->setAccessible(true);
            $consumer = $consumerProperty->getValue($client);
            if (!is_object($consumer)) {
                return;
            }

            $consumerReflection = new ReflectionObject($consumer);
            while (!$consumerReflection->hasProperty('queue')) {
                $parent = $consumerReflection->getParentClass();
                if ($parent === false) {
                    return;
                }
                $consumerReflection = $parent;
            }

            $queueProperty = $consumerReflection->getProperty('queue');
            $queueProperty->setAccessible(true);
            $queueProperty->setValue($consumer, []);
        } catch (Throwable $e) {
            error_log('[adapter] error discarding queued events: ' . $e->getMessage());
        }
    }

    public function toArray(): array
    {
        return [
            'pending_events' => $this->pendingEvents,
            'total_events_captured' => $this->totalEventsCaptured,
            'total_events_sent' => $this->totalEventsSent,
            'total_retries' => $this->totalRetries,
            'last_error' => $this->lastError,
            'requests_made' => array_map(static fn (RequestInfo $r): array => $r->toArray(), $this->requestsMade),
        ];
    }
}

final class TrackedHttpClient extends HttpClient
{
    public function __construct(
        private AdapterState $state,
        private string $trackedHost,
        private bool $trackedUseSsl = true,
        private int $trackedMaximumBackoffDuration = 10000,
        private bool $trackedCompressRequests = false,
        private bool $trackedDebug = false,
        private int $trackedCurlTimeoutMilliseconds = 10000,
    ) {
        parent::__construct(
            $trackedHost,
            $trackedUseSsl,
            $trackedMaximumBackoffDuration,
            $trackedCompressRequests,
            $trackedDebug,
            null,
            $trackedCurlTimeoutMilliseconds,
        );
    }

    public function sendRequest(string $path, ?string $payload, array $extraHeaders = [], array $requestOptions = []): HttpResponse
    {
        $protocol = $this->trackedUseSsl ? 'https://' : 'http://';
        $backoff = 100;
        $shouldRetry = $requestOptions['shouldRetry'] ?? true;
        $shouldVerify = $requestOptions['shouldVerify'] ?? true;
        $includeEtag = $requestOptions['includeEtag'] ?? false;
        $timeout = isset($requestOptions['timeout'])
            ? (int) $requestOptions['timeout']
            : $this->trackedCurlTimeoutMilliseconds;
        $retryAttempt = 0;
        $httpResponse = new HttpResponse(false, 0, null, 0);

        do {
            $ch = curl_init();
            $responseHeaders = [];

            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $headers = ['Content-Type: application/json'];
            if ($this->trackedCompressRequests) {
                $headers[] = 'Content-Encoding: gzip';
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, $extraHeaders));
            curl_setopt($ch, CURLOPT_URL, $protocol . $this->trackedHost . $path);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, $shouldVerify);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $shouldVerify ? $timeout : 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $timeout);
            if (!$shouldVerify) {
                curl_setopt($ch, CURLOPT_NOSIGNAL, true);
                curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
            }
            if ($includeEtag) {
                curl_setopt($ch, CURLOPT_HEADER, true);
            } else {
                curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $header) use (&$responseHeaders): int {
                    $responseHeaders[] = trim($header);
                    return strlen($header);
                });
            }

            $response = curl_exec($ch);
            $responseCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlErrno = (int) curl_errno($ch);
            $etag = null;

            if ($includeEtag && $response !== false) {
                $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $rawHeaders = substr((string) $response, 0, $headerSize);
                $body = substr((string) $response, $headerSize);
                if (preg_match('/^etag:\s*(.+)$/mi', $rawHeaders, $matches)) {
                    $etag = trim($matches[1]);
                }
                $response = $body;
            }

            curl_close($ch);
            $httpResponse = new HttpResponse($response, $responseCode, $etag, $curlErrno);

            if ($path === '/batch/') {
                [$eventCount, $uuidList] = $this->extractBatchInfo($payload);
                $this->state->recordRequest($responseCode, $retryAttempt, $eventCount, $uuidList);
            }

            if ($responseCode === 304) {
                break;
            }

            if ($shouldVerify && $responseCode !== 200) {
                if ($shouldRetry === false) {
                    break;
                }

                if ($this->isRetryableStatus($responseCode)) {
                    $retryAfterMs = $this->retryAfterMilliseconds($responseHeaders);
                    usleep(($retryAfterMs ?? $backoff) * 1000);
                    $backoff *= 2;
                    $retryAttempt++;
                } else {
                    break;
                }
            } else {
                break;
            }
        } while ($shouldRetry && $backoff < $this->trackedMaximumBackoffDuration);

        return $httpResponse;
    }

    /** @return array{0:int,1:list<string>} */
    private function extractBatchInfo(?string $payload): array
    {
        if ($payload === null || $payload === '') {
            return [0, []];
        }

        $json = $payload;
        if ($this->trackedCompressRequests) {
            $decoded = gzdecode($payload);
            if ($decoded !== false) {
                $json = $decoded;
            }
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['batch']) || !is_array($decoded['batch'])) {
            return [0, []];
        }

        $uuidList = [];
        foreach ($decoded['batch'] as $event) {
            if (is_array($event) && isset($event['uuid']) && is_string($event['uuid'])) {
                $uuidList[] = $event['uuid'];
            }
        }

        return [count($decoded['batch']), $uuidList];
    }
}

function isValidUuid(mixed $uuid): bool
{
    return is_string($uuid)
        && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        ) === 1;
}

function jsonResponse($client, int $status, array $payload): void
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        $status = 500;
        $body = '{"error":"failed to encode response"}';
    }

    $reason = [
        200 => 'OK',
        400 => 'Bad Request',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
    ][$status] ?? 'OK';

    fwrite($client, "HTTP/1.1 {$status} {$reason}\r\n");
    fwrite($client, "Content-Type: application/json\r\n");
    fwrite($client, 'Content-Length: ' . strlen($body) . "\r\n");
    fwrite($client, "Connection: close\r\n\r\n");
    fwrite($client, $body);
}

function readRequest($client): ?array
{
    $requestLine = fgets($client);
    if ($requestLine === false || trim($requestLine) === '') {
        return null;
    }

    $parts = explode(' ', trim($requestLine), 3);
    if (count($parts) < 2) {
        return null;
    }

    $headers = [];
    while (($line = fgets($client)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            break;
        }
        $headerParts = explode(':', $line, 2);
        if (count($headerParts) === 2) {
            $headers[strtolower(trim($headerParts[0]))] = trim($headerParts[1]);
        }
    }

    $length = isset($headers['content-length']) ? (int) $headers['content-length'] : 0;
    $body = '';
    while (strlen($body) < $length && !feof($client)) {
        $body .= fread($client, $length - strlen($body));
    }

    return [
        'method' => strtoupper($parts[0]),
        'path' => parse_url($parts[1], PHP_URL_PATH) ?: '/',
        'body' => $body,
    ];
}

function requestJson(array $request): array
{
    if ($request['body'] === '') {
        return [];
    }

    $decoded = json_decode($request['body'], true);
    return is_array($decoded) ? $decoded : [];
}

function normalizeHost(string $host): array
{
    $useSsl = true;
    $normalized = trim($host);
    if (preg_match('/^http:\/\//i', $normalized) === 1) {
        $useSsl = false;
        $normalized = preg_replace('/^http:\/\//i', '', $normalized) ?? $normalized;
    } elseif (preg_match('/^https:\/\//i', $normalized) === 1) {
        $useSsl = true;
        $normalized = preg_replace('/^https:\/\//i', '', $normalized) ?? $normalized;
    }

    return [$normalized, $useSsl];
}

function maxBackoffDurationForRetries(int $maxRetries): int
{
    if ($maxRetries <= 0) {
        return 100;
    }

    return (100 * (2 ** $maxRetries)) + 1;
}

function handleRequest(array $request, AdapterState $state): array
{
    try {
        if ($request['method'] === 'GET' && $request['path'] === '/health') {
            return [200, [
                'sdk_name' => 'posthog-php',
                'sdk_version' => PostHog::VERSION,
                'adapter_version' => '1.0.0',
                'capabilities' => ['capture_v0', 'encoding_gzip'],
            ]];
        }

        if ($request['method'] === 'POST' && $request['path'] === '/init') {
            $data = requestJson($request);
            $apiKey = isset($data['api_key']) ? trim((string) $data['api_key']) : '';
            $host = isset($data['host']) ? trim((string) $data['host']) : '';
            if ($apiKey === '') {
                return [400, ['error' => 'api_key is required']];
            }
            if ($host === '') {
                return [400, ['error' => 'host is required']];
            }

            $state->reset();
            [$normalizedHost, $useSsl] = normalizeHost($host);
            $flushAt = max(1, (int) ($data['flush_at'] ?? 100));
            $flushIntervalMs = max(0, (int) ($data['flush_interval_ms'] ?? 5000));
            $maxRetries = max(0, (int) ($data['max_retries'] ?? 3));
            $enableCompression = (bool) ($data['enable_compression'] ?? false);
            $maximumBackoffDuration = maxBackoffDurationForRetries($maxRetries);
            $timeoutMs = max(1000, (int) ($data['timeout_ms'] ?? 10000));

            $httpClient = new TrackedHttpClient(
                $state,
                $normalizedHost,
                $useSsl,
                $maximumBackoffDuration,
                $enableCompression,
                true,
                $timeoutMs,
            );

            $state->client = new Client($apiKey, [
                'host' => $normalizedHost,
                'ssl' => $useSsl,
                'consumer' => 'lib_curl',
                'batch_size' => $flushAt,
                'flush_interval_seconds' => $flushIntervalMs / 1000,
                'maximum_backoff_duration' => $maximumBackoffDuration,
                'compress_request' => $enableCompression ? 'true' : 'false',
                'debug' => true,
                'timeout' => $timeoutMs,
            ], $httpClient, null, false);

            return [200, ['success' => true]];
        }

        if ($request['method'] === 'POST' && $request['path'] === '/capture') {
            if ($state->client === null) {
                return [400, ['error' => 'SDK not initialized']];
            }

            $data = requestJson($request);
            $distinctId = isset($data['distinct_id']) ? (string) $data['distinct_id'] : '';
            $event = isset($data['event']) ? (string) $data['event'] : '';
            if ($distinctId === '') {
                return [400, ['error' => 'distinct_id is required']];
            }
            if ($event === '') {
                return [400, ['error' => 'event is required']];
            }

            $message = [
                'distinctId' => $distinctId,
                'event' => $event,
                'properties' => (isset($data['properties']) && is_array($data['properties'])) ? $data['properties'] : [],
            ];
            if (isset($data['timestamp'])) {
                $message['timestamp'] = $data['timestamp'];
            }

            if (!isset($message['uuid']) || !isValidUuid($message['uuid'])) {
                $message['uuid'] = Uuid::v4();
            }

            $state->client->capture($message);

            $state->recordCaptured();
            return [200, ['success' => true, 'uuid' => $message['uuid']]];
        }

        if ($request['method'] === 'POST' && $request['path'] === '/get_feature_flag') {
            if ($state->client === null) {
                return [400, ['error' => 'SDK not initialized']];
            }

            $data = requestJson($request);
            $key = isset($data['key']) ? (string) $data['key'] : '';
            $distinctId = isset($data['distinct_id']) ? (string) $data['distinct_id'] : '';
            if ($key === '') {
                return [400, ['error' => 'key is required']];
            }
            if ($distinctId === '') {
                return [400, ['error' => 'distinct_id is required']];
            }

            $groups = (isset($data['groups']) && is_array($data['groups'])) ? $data['groups'] : [];
            $personProperties = (isset($data['person_properties']) && is_array($data['person_properties']))
                ? $data['person_properties']
                : [];
            $groupProperties = (isset($data['group_properties']) && is_array($data['group_properties']))
                ? $data['group_properties']
                : [];
            $forceRemote = (bool) ($data['force_remote'] ?? true);
            $disableGeoip = (bool) ($data['disable_geoip'] ?? false);

            if ($disableGeoip) {
                $snapshot = $state->client->evaluateFlags(
                    $distinctId,
                    $groups,
                    $personProperties,
                    $groupProperties,
                    !$forceRemote,
                    true,
                    [$key],
                );
                $value = $snapshot->getFlag($key);
            } else {
                $value = @$state->client->getFeatureFlag(
                    $key,
                    $distinctId,
                    $groups,
                    $personProperties,
                    $groupProperties,
                    !$forceRemote,
                    true,
                );
            }

            return [200, ['success' => true, 'value' => $value]];
        }

        if ($request['method'] === 'POST' && $request['path'] === '/flush') {
            if ($state->client === null) {
                return [400, ['error' => 'SDK not initialized']];
            }

            $state->client->flush();
            return [200, ['success' => true, 'events_flushed' => $state->totalEventsSent]];
        }

        if ($request['method'] === 'GET' && $request['path'] === '/state') {
            return [200, $state->toArray()];
        }

        if ($request['method'] === 'POST' && $request['path'] === '/reset') {
            $state->reset();
            return [200, ['success' => true]];
        }

        return [404, ['error' => 'not found']];
    } catch (Throwable $e) {
        $state->recordError($e->getMessage());
        error_log('[adapter] ' . $e);
        return [500, ['error' => $e->getMessage()]];
    }
}

$server = stream_socket_server('tcp://0.0.0.0:8080', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Failed to start server: {$errstr} ({$errno})\n");
    exit(1);
}

$state = new AdapterState();
fwrite(STDERR, "PostHog PHP SDK compliance adapter listening on :8080\n");

while (true) {
    $client = @stream_socket_accept($server, -1);
    if ($client === false) {
        usleep(10000);
        continue;
    }

    $request = readRequest($client);
    if ($request === null) {
        fclose($client);
        continue;
    }

    [$status, $payload] = handleRequest($request, $state);
    jsonResponse($client, $status, $payload);
    fclose($client);
}
