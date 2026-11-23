<?php

// A fake of PostHog's email upload, storage and code-send routes that follows the server's schema and category rules.
// It writes what it stored to the state file after each request. Its setup, in place of the canned responses,
// takes `opted_out`, which maps a category key, or `$all`, to the recipients who opted out of it, and
// `templates`, which maps a template_key that names a template made in PostHog to that template's category,
// or null for a template with no category.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

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
$optedOut = $setup['opted_out'] ?? [];
$templates = $setup['templates'] ?? [];
echo $selfAddress . "\n";
flush();

$uploadsRoute = 'POST /api/messaging/attachments/uploads/ HTTP/1.1';
$sendRoute = 'POST /api/messaging/send/email/ HTTP/1.1';
$codeSendFields = [
    'to', 'distinct_id', 'template_key', 'subject', 'html', 'text', 'from', 'reply_to', 'capture_engagement',
    'sensitive', 'tracking', 'category', 'cc', 'bcc', 'attachments',
];
$attachmentFields = ['sha256', 'filename', 'content_type', 'content_id'];
$categoryFields = ['key', 'type', 'name', 'description'];
$categoryTypes = ['marketing', 'transactional'];
$rawTextTags = ['textarea', 'title', 'script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript'];

$state = ['categories' => [], 'sends' => []];
$signedUploads = [];
$storedAttachments = [];
foreach (array_filter($templates) as $template) {
    $state['categories'][$template['key']] = [
        'type' => $template['type'],
        'name' => $template['name'],
        'description' => '',
    ];
}

$refuse = static fn(int $status, string $code, string $detail, ?string $attr = null): array
    => [$status, ['type' => 'validation_error', 'code' => $code, 'detail' => $detail, 'attr' => $attr]];
$isText = static fn(mixed $value, int $maxLength): bool
    => is_string($value) && trim($value) !== '' && mb_strlen(trim($value), 'UTF-8') <= $maxLength;

$placeholder = '\{\{\s*unsubscribe_url\s*\}\}';
$unsubscribeHref = "/^\s*$placeholder(?:[?#].*)?\s*$/u";

$hasClickableUnsubscribeLink = static function (string $html) use ($unsubscribeHref, $rawTextTags): bool {
    $len = strlen($html);
    $pos = 0;
    while ($pos < $len) {
        $open = strpos($html, '<', $pos);
        if ($open === false || $open + 1 >= $len) {
            return false;
        }
        $pos = $open + 1;
        if (substr($html, $pos, 3) === '!--') {
            $endComment = strpos($html, '-->', $pos + 3);
            if ($endComment === false) {
                return false;
            }
            $pos = $endComment + 3;
            continue;
        }
        $first = $html[$pos];
        if ($first === '/' || $first === '!' || $first === '?') {
            $close = strpos($html, '>', $pos);
            if ($close === false) {
                return false;
            }
            $pos = $close + 1;
            continue;
        }
        if (!ctype_alpha($first)) {
            continue;
        }
        $tagStart = $pos;
        while ($pos < $len && !ctype_space($html[$pos]) && $html[$pos] !== '>' && $html[$pos] !== '/') {
            $pos++;
        }
        $tagName = strtolower(substr($html, $tagStart, $pos - $tagStart));
        $isAnchor = $tagName === 'a';
        $sawHref = false;
        $matched = false;
        while ($pos < $len && $html[$pos] !== '>') {
            while ($pos < $len && ctype_space($html[$pos])) {
                $pos++;
            }
            if ($pos >= $len || $html[$pos] === '>') {
                break;
            }
            if ($html[$pos] === '/') {
                $pos++;
                continue;
            }
            $nameStart = $pos;
            while (
                $pos < $len
                && !ctype_space($html[$pos])
                && $html[$pos] !== '='
                && $html[$pos] !== '>'
                && $html[$pos] !== '/'
            ) {
                $pos++;
            }
            $name = substr($html, $nameStart, $pos - $nameStart);
            while ($pos < $len && ctype_space($html[$pos])) {
                $pos++;
            }
            if ($pos < $len && $html[$pos] === '=') {
                $pos++;
                while ($pos < $len && ctype_space($html[$pos])) {
                    $pos++;
                }
                if ($pos < $len && ($html[$pos] === '"' || $html[$pos] === "'")) {
                    $quote = $html[$pos++];
                    $valStart = $pos;
                    while ($pos < $len && $html[$pos] !== $quote) {
                        $pos++;
                    }
                    $value = substr($html, $valStart, $pos - $valStart);
                    if ($pos < $len) {
                        $pos++;
                    }
                } else {
                    $valStart = $pos;
                    while ($pos < $len && !ctype_space($html[$pos]) && $html[$pos] !== '>') {
                        $pos++;
                    }
                    $value = substr($html, $valStart, $pos - $valStart);
                }
                if ($isAnchor && !$sawHref && strtolower($name) === 'href') {
                    $sawHref = true;
                    $matched = preg_match($unsubscribeHref, $value) === 1;
                }
            } else {
                if ($isAnchor && !$sawHref && strtolower($name) === 'href') {
                    $sawHref = true;
                }
                if ($name === '') {
                    $pos++;
                }
            }
        }
        if ($pos >= $len) {
            return false;
        }
        if ($matched) {
            return true;
        }
        if (in_array($tagName, $rawTextTags, true)) {
            if (preg_match("/<\/$tagName(?:\s|>|\/)/i", $html, $endTag, PREG_OFFSET_CAPTURE, $pos + 1) === 1) {
                $close = strpos($html, '>', $endTag[0][1]);
                if ($close === false) {
                    return false;
                }
                $pos = $close + 1;
                continue;
            }
            return false;
        }
        $pos++;
    }

    return false;
};

$uploads = static function (string $body) use ($selfAddress, $refuse, &$signedUploads, &$storedAttachments): array {
    $payload = json_decode($body, true);
    if (!is_array($payload) || !isset($payload['files']) || !is_array($payload['files']) || $payload['files'] === []) {
        return $refuse(400, 'invalid_request', 'Send 1 to 10 files.', 'files');
    }
    $answers = [];
    foreach ($payload['files'] as $file) {
        $sha256 = $file['sha256'] ?? null;
        $size = $file['size'] ?? null;
        if (
            !is_array($file)
            || !is_string($sha256)
            || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1
            || !is_int($size)
            || $size < 1
        ) {
            return $refuse(400, 'invalid_request', 'Each file takes sha256 and size.', 'files');
        }
        if (array_key_exists($sha256, $storedAttachments)) {
            $answers[] = ['sha256' => $sha256, 'status' => 'present', 'expires_at' => '2026-12-16T12:00:00Z'];
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
            'url_expires_at' => '2026-11-02T12:15:00Z',
        ];
    }

    return [200, ['files' => $answers]];
};

$storeUpload = static function (
    string $requestLine,
    array $headers,
    string $body
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

    return [200, null];
};

$send = static function (
    string $requestLine,
    array $headers,
    string $body,
    array &$state
) use (
    $uploadsRoute,
    $sendRoute,
    $codeSendFields,
    $attachmentFields,
    $categoryFields,
    $categoryTypes,
    $optedOut,
    $templates,
    $placeholder,
    $refuse,
    $isText,
    $hasClickableUnsubscribeLink,
    $uploads,
    $storeUpload,
    &$storedAttachments
): array {
    if (str_starts_with($requestLine, 'PUT /attachments/')) {
        return $storeUpload($requestLine, $headers, $body);
    }
    if ($requestLine === $uploadsRoute) {
        return $uploads($body);
    }
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
    $category = $email['category'] ?? null;
    if ($category !== null && $category !== 'transactional') {
        if (!is_array($category)) {
            $shape = 'Send transactional, or a category with a key, type and name.';
            return $refuse(400, 'invalid_input', $shape, 'category');
        }
        $trimmedKey = is_string($category['key'] ?? null) ? trim($category['key']) : null;
        $trimmedDescription = array_key_exists('description', $category) && is_string($category['description'])
            ? trim($category['description'])
            : null;
        $valid = array_diff(array_keys($category), $categoryFields) === []
            && $isText($category['key'] ?? null, 64) && $trimmedKey !== '$all'
            && in_array($category['type'] ?? null, $categoryTypes, true)
            && $isText($category['name'] ?? null, 128)
            && (
                !array_key_exists('description', $category)
                || ($trimmedDescription !== null && mb_strlen($trimmedDescription, 'UTF-8') <= 1000)
            );
        if (!$valid) {
            $shape = 'Send transactional, or a category with a key, type and name.';
            return $refuse(400, 'invalid_input', $shape, 'category');
        }
    }
    $attachments = [];
    if (array_key_exists('attachments', $email)) {
        if (!is_array($email['attachments']) || $email['attachments'] === [] || count($email['attachments']) > 10) {
            return $refuse(400, 'invalid_request', 'Send 1 to 10 attachments.', 'attachments');
        }
        foreach ($email['attachments'] as $index => $attachment) {
            $sha256 = $attachment['sha256'] ?? null;
            $filename = $attachment['filename'] ?? null;
            if (
                !is_array($attachment)
                || array_diff(array_keys($attachment), $attachmentFields) !== []
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
            $attachments[] = $attachment;
        }
    }
    $html = $email['html'] ?? '';
    $content = $html !== '' ? $html : ($email['text'] ?? '');
    $unsubscribeLinkRequired = 'A marketing email needs a {{ unsubscribe_url }} link.';
    $hasCopies = !empty($email['cc']) || !empty($email['bcc']);
    if (array_key_exists($email['template_key'], $templates)) {
        $template = $templates[$email['template_key']];
        if (($template['type'] ?? null) === 'marketing') {
            $onlyTransactional = 'The template is in a marketing category. Only transactional templates can be sent.';
            return $refuse(400, 'marketing_template', $onlyTransactional);
        }
        $matches = $template === null
            ? in_array($category, [null, 'transactional'], true)
            : $category === null || (
                is_array($category)
                && trim($category['key']) === $template['key']
                && $category['type'] === $template['type']
            );
        if (!$matches) {
            return $refuse(400, 'category_mismatch', 'The template sends in another category.', 'category');
        }
        $key = $template['key'] ?? null;
        $type = $template['type'] ?? 'transactional';
    } elseif (is_array($category)) {
        $key = trim($category['key']);
        $type = $category['type'];
        if ($type === 'marketing') {
            if (preg_match("/$placeholder/u", $content) !== 1) {
                return $refuse(400, 'unsubscribe_link_required', $unsubscribeLinkRequired, 'html');
            }
            if ($hasCopies) {
                return $refuse(400, 'copies_not_allowed', 'A marketing email cannot have cc or bcc recipients.');
            }
        }
        $stored = $state['categories'][$key] ?? null;
        if ($stored !== null && $stored['type'] !== $type) {
            return $refuse(400, 'category_mismatch', "The category is {$stored['type']}.", 'category');
        }
        $state['categories'][$key] ??= [
            'type' => $type,
            'name' => trim($category['name']),
            'description' => array_key_exists('description', $category) ? trim($category['description']) : '',
        ];
    } else {
        $key = null;
        $type = 'transactional';
    }
    if ($type === 'marketing') {
        if ($attachments !== []) {
            return $refuse(400, 'workflow_can_wait', 'A marketing email cannot carry attachments.', 'attachments');
        }
        if ($html !== '' && !$hasClickableUnsubscribeLink($html)) {
            return $refuse(400, 'unsubscribe_link_required', $unsubscribeLinkRequired, 'html');
        }
        $optOuts = $key === null ? [] : array_merge($optedOut[$key] ?? [], $optedOut['$all'] ?? []);
        if (in_array($email['to'], $optOuts, true)) {
            return $refuse(422, 'recipient_opted_out', 'The recipient opted out of this category.');
        }
    }
    foreach ($attachments as $index => $attachment) {
        if (!array_key_exists($attachment['sha256'], $storedAttachments)) {
            return $refuse(
                400,
                'attachment_not_uploaded',
                "A file this email names isn't uploaded, so PostHog didn't send the email.",
                "attachments__{$index}__sha256"
            );
        }
    }
    $messageId = sprintf('01a18356-c56c-0001-e494-%012d', count($state['sends']) + 1);
    $sendRecord = ['message_id' => $messageId, 'category' => $key, 'type' => $type];
    if ($attachments !== []) {
        $sendRecord['attachments'] = $attachments;
    }
    $state['sends'][] = $sendRecord;

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
    [$status, $reply] = $send($requestLine, $headers, $body, $state);
    file_put_contents($argv[1], json_encode([
        'requestLine' => $requestLine,
        'headers' => $headers,
        'body' => $body,
        'status' => $status,
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
    $replyBody = $reply === null ? '' : json_encode($reply, JSON_THROW_ON_ERROR);
    fwrite($connection, "HTTP/1.1 $status Response\r\nContent-Type: application/json\r\nContent-Length: "
        . strlen($replyBody) . "\r\nConnection: close\r\n\r\n" . $replyBody);
    fclose($connection);
}
