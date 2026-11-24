<?php

require_once __DIR__ . '/../CanonicalJson.php';

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException($error, $errno);
}
$responses = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$rawResponses = json_decode($argv[2], false, 512, JSON_THROW_ON_ERROR);
$selfAddress = stream_socket_get_name($server, false);
echo $selfAddress . "\n";
flush();

while ($connection = stream_socket_accept($server, 30)) {
    stream_set_timeout($connection, 5);
    $requestLine = trim(fgets($connection));
    $headers = [];
    while (($line = fgets($connection)) !== false && trim($line) !== '') {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower($name)] = trim($value);
    }
    if (strtolower($headers['expect'] ?? '') === '100-continue') {
        fwrite($connection, "HTTP/1.1 100 Continue\r\n\r\n");
    }
    $length = (int) ($headers['content-length'] ?? 0);
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($connection, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Incomplete request body');
        }
        $body .= $chunk;
    }
    if (($headers['content-encoding'] ?? '') === 'gzip') {
        $body = gzdecode($body);
    }
    $next = $responses[0] ?? [];
    $rawNext = $rawResponses[0] ?? null;
    $refusal = null;
    foreach ($next['accepts'] ?? [] as $name => $expected) {
        if ($name === 'json_body') {
            $decoded = json_decode($body, false);
            $rawExpected = $rawNext?->accepts?->json_body ?? null;
            $matches = json_last_error() === JSON_ERROR_NONE
                && \PostHog\Test\CanonicalJson::encode($decoded)
                    === \PostHog\Test\CanonicalJson::encode($rawExpected);
            if (!$matches) {
                $refusal = $next['refusal'];
            }
            continue;
        }
        $actual = match ($name) {
            'request_line' => $requestLine,
            'route' => implode(' ', array_slice(explode(' ', $requestLine, 3), 0, 2)),
            default => $headers[$name] ?? null,
        };
        if ($actual !== $expected) {
            $refusal = $next['refusal'];
        }
    }
    $checksum = $headers['x-amz-checksum-sha256'] ?? null;
    if ($checksum !== null && $checksum !== base64_encode(hash('sha256', $body, true))) {
        $refusal = ['status' => 400, 'body' => '<?xml version="1.0" encoding="UTF-8"?><Error><Code>BadDigest</Code>'
            . '<Message>The SHA256 you specified did not match the calculated checksum.</Message></Error>'];
    }
    if (str_contains($requestLine, 'X-Amz-Signature=') && isset($headers['authorization'])) {
        $refusal = ['status' => 400, 'body' => '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Error><Code>InvalidArgument</Code><Message>Only one auth mechanism allowed</Message></Error>'];
    }
    if ($refusal === null) {
        $response = array_shift($responses) ?? [];
        array_shift($rawResponses);
    } else {
        $response = $refusal;
    }
    $entry = [
        'requestLine' => $requestLine,
        'headers' => $headers,
        'status' => $response['status'] ?? 200,
    ];
    if (mb_check_encoding($body, 'UTF-8')) {
        $entry['body'] = $body;
    } else {
        $entry['body_base64'] = base64_encode($body);
    }
    file_put_contents($argv[1], json_encode($entry, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    usleep(($response['delay_ms'] ?? 0) * 1000);
    $closeServer = $response['close_server'] ?? false;
    if ($closeServer) {
        fclose($server);
    }
    $status = $response['status'] ?? 200;
    $body = str_replace('__SELF__', (string) $selfAddress, $response['body'] ?? '{"status":1}')
        . str_repeat(' ', $response['pad_body_bytes'] ?? 0);
    $headers = '';
    foreach ($response['headers'] ?? [] as $name => $value) {
        $headers .= "$name: $value\r\n";
    }
    $wire = $response['raw_response'] ?? "HTTP/1.1 $status Response\r\n" . $headers
        . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    while ($wire !== '') {
        $written = fwrite($connection, $wire);
        if ($written === false || $written === 0) {
            break;
        }
        $wire = substr($wire, $written);
    }
    if (isset($response['raw_response'])) {
        while (!feof($connection)) {
            $chunk = fread($connection, 2048);
            if ($chunk === false || $chunk === '') {
                break;
            }
        }
    }
    fclose($connection);
    if ($closeServer) {
        break;
    }
}
