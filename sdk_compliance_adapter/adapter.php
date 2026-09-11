<?php

// phpcs:ignoreFile -- Compliance adapter is an executable test harness shim.
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PostHog\Client;

final class AdapterState
{
    public ?Client $client = null;
    public int $totalEventsCaptured = 0;
    public ?string $lastUuid = null;
    public ?string $lastError = null;

    public function observeCapture(array $message): array
    {
        $this->totalEventsCaptured++;
        $this->lastUuid = $message['uuid'] ?? null;
        return $message;
    }

    public function recordError(string $error): void
    {
        $this->lastError = $error;
    }

    public function toArray(): array
    {
        return [
            // The public SDK has no queue-length accessor. Do not infer delivery from enqueue.
            'pending_events' => null,
            'total_events_captured' => $this->totalEventsCaptured,
            'last_error' => $this->lastError,
        ];
    }
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

function maxBackoffDurationForRetries(int $maxRetries, string $consumer): int
{
    if ($maxRetries <= 0) {
        return 100;
    }

    // Socket checks its limit before sleeping; HttpClient checks after doubling.
    return (100 * (2 ** $maxRetries)) + ($consumer === 'socket' ? 0 : 1);
}

function handleRequest(array $request, AdapterState $state): array
{
    try {
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

            [$normalizedHost, $useSsl] = normalizeHost($host);
            $flushAt = max(1, (int) ($data['flush_at'] ?? 100));
            $flushIntervalMs = max(0, (int) ($data['flush_interval_ms'] ?? 5000));
            $maxRetries = max(0, (int) ($data['max_retries'] ?? 3));
            $enableCompression = (bool) ($data['enable_compression'] ?? false);
            $timeoutMs = max(1000, (int) ($data['timeout_ms'] ?? 10000));

            $consumer = getenv('POSTHOG_CONSUMER') ?: 'lib_curl';
            if (!in_array($consumer, ['lib_curl', 'socket', 'fork_curl'], true)) {
                throw new InvalidArgumentException('Unsupported consumer: ' . $consumer);
            }

            $maximumBackoffDuration = maxBackoffDurationForRetries($maxRetries, $consumer);
            $state->client = new Client($apiKey, [
                'host' => $normalizedHost,
                'ssl' => $useSsl,
                'consumer' => $consumer,
                'batch_size' => $flushAt,
                'flush_interval_seconds' => $flushIntervalMs / 1000,
                'maximum_backoff_duration' => $maximumBackoffDuration,
                'compress_request' => $enableCompression ? 'true' : 'false',
                'debug' => true,
                'timeout' => $consumer === 'socket' ? $timeoutMs / 1000 : $timeoutMs,
                'before_send' => [$state, 'observeCapture'],
                'error_handler' => static function ($code, $message) use ($state): void {
                    $state->recordError(is_string($message) ? $message : json_encode($message));
                },
            ], null, null, false);

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

            $state->lastUuid = null;
            $success = $state->client->capture($message);
            if (!$success) {
                $state->recordError('SDK capture returned false');
            }
            return [200, ['success' => $success, 'uuid' => $state->lastUuid]];
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

            $success = $state->client->flush();
            if (!$success) {
                $state->recordError('SDK flush returned false');
            }
            return [200, ['success' => $success]];
        }

        if ($request['method'] === 'GET' && $request['path'] === '/state') {
            return [200, $state->toArray()];
        }

        return [404, ['error' => 'not found']];
    } catch (Throwable $e) {
        $state->recordError($e->getMessage());
        error_log('[adapter] ' . $e);
        return [500, ['error' => $e->getMessage()]];
    }
}

// One SDK instance per process. The controller owns reset and terminates this worker
// without running destructors, so queued events cannot leak into the next test.
$state = new AdapterState();
while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    echo json_encode(handleRequest($request, $state), JSON_THROW_ON_ERROR) . "\n";
    fflush(STDOUT);
}
