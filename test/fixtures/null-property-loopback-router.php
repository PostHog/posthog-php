<?php

// Invoked only by NullPropertyLoopbackTest's owned, numeric-loopback PHP server.
if ($_SERVER['REQUEST_URI'] === '/ready') {
    echo 'ready';
    return;
}
if ($_SERVER['REQUEST_URI'] !== '/batch/' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    return;
}
$body = file_get_contents('php://input');
if (($_SERVER['HTTP_CONTENT_ENCODING'] ?? '') === 'gzip') {
    $body = gzdecode($body);
}
file_put_contents(getenv('POSTHOG_TEST_BODIES'), $body . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
echo '{}';
