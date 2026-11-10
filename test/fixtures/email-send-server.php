<?php

// A fake of PostHog's code-send route that follows the server's request schema and its category rules.
// It writes what it stored to the state file after each request. Its setup, in place of the canned responses,
// maps a category key, or `$all`, to the recipients who opted out of it.

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException($error, $errno);
}
$stateFile = $argv[1] . '.state';
$optedOut = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR)['opted_out'] ?? [];
echo stream_socket_get_name($server, false) . "\n";
flush();

$sendRoute = 'POST /api/projects/@current/messaging/send/email/ HTTP/1.1';
$codeSendFields = [
    'to', 'distinct_id', 'template_key', 'subject', 'html', 'text', 'from', 'reply_to', 'capture_engagement',
    'category', 'cc', 'bcc', 'attachments',
];
$categoryFields = ['key', 'type', 'name', 'description'];
$categoryTypes = ['marketing', 'transactional'];

$state = ['categories' => [], 'sends' => []];

$refuse = static fn(int $status, string $code, string $detail, ?string $attr = null): array
    => [$status, ['type' => 'validation_error', 'code' => $code, 'detail' => $detail, 'attr' => $attr]];
$isText = static fn(mixed $value, int $maxLength): bool
    => is_string($value) && trim($value) !== '' && strlen($value) <= $maxLength;

$send = static function (
    string $requestLine,
    string $body,
    array &$state
) use (
    $sendRoute,
    $codeSendFields,
    $categoryFields,
    $categoryTypes,
    $optedOut,
    $refuse,
    $isText
): array {
    if ($requestLine !== $sendRoute) {
        return $refuse(404, 'not_found', 'Not found.');
    }
    $email = json_decode($body, true);
    if (!is_array($email) || !isset($email['template_key'])) {
        return $refuse(400, 'invalid_input', 'This fake takes only a send with template_key.');
    }
    foreach (array_keys($email) as $field) {
        if (!in_array($field, $codeSendFields, true)) {
            return $refuse(400, 'invalid_input', "PostHog doesn't take this field.", $field);
        }
    }
    $category = $email['category'] ?? 'transactional';
    $type = 'transactional';
    $key = null;
    if ($category !== 'transactional') {
        $valid = is_array($category)
            && array_diff(array_keys($category), $categoryFields) === []
            && $isText($category['key'] ?? null, 64) && $category['key'] !== '$all'
            && in_array($category['type'] ?? null, $categoryTypes, true)
            && $isText($category['name'] ?? null, 128)
            && (!array_key_exists('description', $category) || is_string($category['description']));
        if (!$valid) {
            $shape = 'Send transactional, or a category with a key, type and name.';
            return $refuse(400, 'invalid_input', $shape, 'category');
        }
        $key = $category['key'];
        $type = $category['type'];
        $stored = $state['categories'][$key] ?? null;
        if ($stored !== null && $stored['type'] !== $type) {
            return $refuse(400, 'category_mismatch', "The category is {$stored['type']}.", 'category');
        }
        $optOuts = array_merge($optedOut[$key] ?? [], $optedOut['$all'] ?? []);
        if ($type === 'marketing' && in_array($email['to'], $optOuts, true)) {
            return $refuse(422, 'recipient_opted_out', 'The recipient opted out of this category.');
        }
        $state['categories'][$key] = [
            'type' => $type,
            'name' => $category['name'],
            'description' => array_key_exists('description', $category)
                ? $category['description']
                : ($stored['description'] ?? ''),
        ];
    }
    $messageId = sprintf('01a18356-c56c-0001-e494-%012d', count($state['sends']) + 1);
    $state['sends'][] = ['message_id' => $messageId, 'category' => $key, 'type' => $type];

    return [202, ['message_id' => $messageId, 'replayed' => false]];
};

while ($connection = stream_socket_accept($server, 30)) {
    stream_set_timeout($connection, 5);
    $requestLine = trim(fgets($connection));
    $headers = [];
    while (($line = fgets($connection)) !== false && trim($line) !== '') {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower($name)] = trim($value);
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
    file_put_contents($argv[1], json_encode([
        'requestLine' => $requestLine,
        'headers' => $headers,
        'body' => $body,
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

    [$status, $reply] = $send($requestLine, $body, $state);
    file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
    $reply = json_encode($reply, JSON_THROW_ON_ERROR);
    fwrite($connection, "HTTP/1.1 $status Response\r\nContent-Type: application/json\r\nContent-Length: "
        . strlen($reply) . "\r\nConnection: close\r\n\r\n" . $reply);
    fclose($connection);
}
