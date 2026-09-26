<?php

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException($error, $errno);
}
$responses = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
echo stream_socket_get_name($server, false) . "\n";
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
    file_put_contents($argv[1], json_encode([
        'requestLine' => $requestLine,
        'headers' => $headers,
        'body' => $body,
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    $response = array_shift($responses) ?? [];
    $status = $response['status'] ?? 200;
    $body = $response['body'] ?? '{"status":1}';
    $headers = '';
    foreach ($response['headers'] ?? [] as $name => $value) {
        $headers .= "$name: $value\r\n";
    }
    $wire = "HTTP/1.1 $status Response\r\n" . $headers
        . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    while ($wire !== '') {
        $written = fwrite($connection, $wire);
        if ($written === false || $written === 0) {
            break;
        }
        $wire = substr($wire, $written);
    }
    fclose($connection);
}
