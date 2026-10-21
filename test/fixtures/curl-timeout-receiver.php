<?php

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error);
    exit(1);
}
echo stream_socket_get_name($server, false) . "\n";
flush();
$connection = stream_socket_accept($server, 10);
if ($connection === false) {
    exit(1);
}
stream_set_timeout($connection, 30);
$length = 0;
while (($line = fgets($connection)) !== false && trim($line) !== '') {
    if (stripos($line, 'Content-Length:') === 0) {
        $length = (int) substr($line, strlen('Content-Length:'));
    }
}
while ($length > 0 && ($chunk = fread($connection, $length)) !== false && $chunk !== '') {
    $length -= strlen($chunk);
}
echo "received\n";
flush();
if (($argv[1] ?? '') === 'respond') {
    usleep(200000);
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
} else {
    while (!feof($connection)) {
        if (fread($connection, 8192) === false) {
            break;
        }
    }
}
fclose($connection);
fclose($server);
echo "closed\n";
