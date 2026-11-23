<?php

// A recording HTTP stub for PostHog's email upload, storage and code-send routes.
// It validates JSON container shapes (preserving JSON arrays vs objects) and signed attachment uploads,
// stores uploaded binary bytes in `$state['stored']` (base64-encoded), and returns scripted responses for
// `POST /api/messaging/send/email/` (defaulting to 202 Accepted) without simulating server policy rules.

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException($error, $errno);
}
$selfAddress = stream_socket_get_name($server, false);
if ($selfAddress === false) {
    throw new RuntimeException('Could not determine fake server address');
}
$stateFile = $argv[1] . '.state';
$setup = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$sendResponses = $setup['send_responses'] ?? [];
echo $selfAddress . "\n";
flush();

$uploadsRoute = 'POST /api/messaging/attachments/uploads/ HTTP/1.1';
$sendRoute = 'POST /api/messaging/send/email/ HTTP/1.1';
$codeSendFields = [
    'to', 'distinct_id', 'template_key', 'subject', 'html', 'text', 'from', 'reply_to', 'capture_engagement',
    'sensitive', 'tracking', 'category', 'cc', 'bcc', 'attachments',
];
$uploadFileFields = ['sha256', 'size'];
$attachmentFields = ['sha256', 'filename', 'content_type', 'content_id'];

$state = ['stored' => []];
$signedUploads = [];
$storedAttachments = [];
$sendCount = 0;

$refuse = static fn(int $status, string $code, string $detail, ?string $attr = null): array
    => [$status, ['type' => 'invalid_request', 'code' => $code, 'detail' => $detail, 'attr' => $attr]];

$decodeObject = static function (string $body): ?stdClass {
    try {
        $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return $decoded instanceof stdClass ? $decoded : null;
};

$uploads = static function (
    string $body
) use (
    $selfAddress,
    $uploadFileFields,
    $refuse,
    $decodeObject,
    &$signedUploads,
    &$storedAttachments
): array {
    $payload = $decodeObject($body);
    if ($payload === null) {
        return $refuse(400, 'invalid_request', 'Expected a JSON object.', 'files');
    }
    $keys = array_keys(get_object_vars($payload));
    if (
        array_diff($keys, ['files']) !== []
        || !property_exists($payload, 'files')
        || !is_array($payload->files)
        || $payload->files === []
        || count($payload->files) > 10
    ) {
        return $refuse(400, 'invalid_request', 'Send 1 to 10 files.', 'files');
    }
    $answers = [];
    foreach ($payload->files as $file) {
        if (!$file instanceof stdClass) {
            return $refuse(400, 'invalid_request', 'Each file takes only sha256 and size.', 'files');
        }
        $fileKeys = array_keys(get_object_vars($file));
        $sha256 = $file->sha256 ?? null;
        $size = $file->size ?? null;
        if (
            array_diff($fileKeys, $uploadFileFields) !== []
            || !is_string($sha256)
            || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1
            || !is_int($size)
            || $size < 1
        ) {
            return $refuse(400, 'invalid_request', 'Each file takes only sha256 and size.', 'files');
        }
        if (array_key_exists($sha256, $storedAttachments)) {
            $answers[] = [
                'sha256' => $sha256,
                'status' => 'present',
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
            ];
            continue;
        }
        $checksum = base64_encode((string) hex2bin($sha256));
        $path = "/attachments/team/2/$sha256?X-Amz-Signature=abc";
        $signedUploads[$sha256] = ['path' => $path, 'size' => $size, 'checksum' => $checksum];
        $answers[] = [
            'sha256' => $sha256,
            'status' => 'upload',
            'url' => "http://$selfAddress$path",
            'method' => 'PUT',
            'headers' => ['Content-Length' => (string) $size, 'x-amz-checksum-sha256' => $checksum],
            'url_expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 900),
        ];
    }

    return [200, ['files' => $answers]];
};

$storeUpload = static function (
    string $requestLine,
    array $headers,
    string $body,
    array &$state
) use (
    &$signedUploads,
    &$storedAttachments
): array {
    if (isset($headers['authorization'])) {
        return [400, ['error' => 'Only one auth mechanism allowed.']];
    }
    $putPattern = '#^PUT (/attachments/team/2/([0-9a-f]{64})\?X-Amz-Signature=abc) HTTP/1\.1$#';
    if (preg_match($putPattern, $requestLine, $m) !== 1) {
        return [403, ['error' => 'SignatureDoesNotMatch']];
    }
    $path = $m[1];
    $sha256 = $m[2];
    $signed = $signedUploads[$sha256] ?? null;
    if (
        $signed === null
        || $signed['path'] !== $path
        || ($headers['content-length'] ?? null) !== (string) $signed['size']
        || ($headers['x-amz-checksum-sha256'] ?? null) !== $signed['checksum']
    ) {
        return [403, ['error' => 'SignatureDoesNotMatch']];
    }
    if (hash('sha256', $body) !== $sha256) {
        return [400, ['error' => 'BadDigest']];
    }
    $storedAttachments[$sha256] = $body;
    $state['stored'][$sha256] = base64_encode($body);

    return [200, null];
};

$handle = static function (
    string $requestLine,
    array $headers,
    string $body,
    array &$state
) use (
    $uploadsRoute,
    $sendRoute,
    $codeSendFields,
    $attachmentFields,
    $refuse,
    $decodeObject,
    $uploads,
    $storeUpload,
    &$storedAttachments,
    &$sendResponses,
    &$sendCount
): array {
    if (str_starts_with($requestLine, 'PUT /attachments/')) {
        return $storeUpload($requestLine, $headers, $body, $state);
    }
    if ($requestLine === $uploadsRoute) {
        return $uploads($body);
    }
    if ($requestLine !== $sendRoute) {
        return $refuse(404, 'not_found', 'Not found.');
    }
    $email = $decodeObject($body);
    if ($email === null || !property_exists($email, 'template_key')) {
        return $refuse(400, 'invalid_input', 'This stub takes only a send with template_key.');
    }
    foreach (array_keys(get_object_vars($email)) as $field) {
        if (!in_array($field, $codeSendFields, true)) {
            return $refuse(400, 'invalid_input', "PostHog doesn't take this field.", $field);
        }
    }
    if (property_exists($email, 'attachments')) {
        if (!is_array($email->attachments) || $email->attachments === [] || count($email->attachments) > 10) {
            return $refuse(400, 'invalid_request', 'Send 1 to 10 attachments.', 'attachments');
        }
        foreach ($email->attachments as $index => $attachment) {
            if (!$attachment instanceof stdClass) {
                return $refuse(
                    400,
                    'invalid_request',
                    'An attachment takes sha256, filename, content_type and content_id.',
                    "attachments__{$index}"
                );
            }
            $attachmentKeys = array_keys(get_object_vars($attachment));
            $sha256 = $attachment->sha256 ?? null;
            $filename = $attachment->filename ?? null;
            if (
                array_diff($attachmentKeys, $attachmentFields) !== []
                || !is_string($sha256)
                || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1
                || !is_string($filename)
                || $filename === ''
            ) {
                return $refuse(
                    400,
                    'invalid_request',
                    'An attachment takes sha256, filename, content_type and content_id.',
                    "attachments__{$index}"
                );
            }
            if (!array_key_exists($sha256, $storedAttachments)) {
                return $refuse(
                    400,
                    'attachment_not_uploaded',
                    "A file this email names isn't uploaded, so PostHog didn't send the email.",
                    "attachments__{$index}__sha256"
                );
            }
        }
    }
    $scripted = array_shift($sendResponses);
    if (is_array($scripted)) {
        return [$scripted['status'], $scripted['body'] ?? null];
    }
    $sendCount++;
    $messageId = sprintf('01a18356-c56c-0001-e494-%012d', $sendCount);

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
    [$status, $reply] = $handle($requestLine, $headers, $body, $state);
    $entry = [
        'requestLine' => $requestLine,
        'headers' => $headers,
        'status' => $status,
    ];
    if (str_starts_with($requestLine, 'PUT ')) {
        $entry['body_base64'] = base64_encode($body);
    } else {
        $entry['body'] = $body;
    }
    file_put_contents($argv[1], json_encode($entry, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
    $replyBody = $reply === null ? '' : json_encode($reply, JSON_THROW_ON_ERROR);
    fwrite($connection, "HTTP/1.1 $status Response\r\nContent-Type: application/json\r\nContent-Length: "
        . strlen($replyBody) . "\r\nConnection: close\r\n\r\n" . $replyBody);
    fclose($connection);
}
