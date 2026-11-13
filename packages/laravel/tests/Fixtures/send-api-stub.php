<?php

// A loopback stand-in for PostHog's send API: answers each request with the next queued response and
// appends the request it saw to a file, one JSON line per request.
// Usage: php send-api-stub.php <requests file> <responses JSON>

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error);
    exit(1);
}
$responses = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
echo stream_socket_get_name($server, false) . "\n";
flush();

while ($connection = @stream_socket_accept($server, 30)) {
    stream_set_timeout($connection, 5);
    $requestLine = trim((string) fgets($connection));
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
            break;
        }
        $body .= $chunk;
    }
    if (($headers['content-encoding'] ?? '') === 'gzip') {
        $body = gzdecode($body);
    }
    file_put_contents($argv[1], json_encode(
        ['requestLine' => $requestLine, 'headers' => $headers, 'body' => $body],
        JSON_THROW_ON_ERROR
    ) . "\n", FILE_APPEND);

    $response = array_shift($responses) ?? ['status' => 500, 'body' => '{"type":"server_error"}'];
    $status = $response['status'];
    $payload = $response['body'] ?? '';
    $extra = '';
    foreach ($response['headers'] ?? [] as $name => $value) {
        $extra .= "$name: $value\r\n";
    }
    fwrite($connection, "HTTP/1.1 $status Response\r\nContent-Type: application/json\r\n$extra"
        . 'Content-Length: ' . strlen($payload) . "\r\nConnection: close\r\n\r\n" . $payload);
    fclose($connection);
}
