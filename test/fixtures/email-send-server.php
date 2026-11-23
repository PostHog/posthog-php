<?php

// A fake of PostHog's code-send route that follows the server's request schema and its category rules.
// It writes what it stored to the state file after each request. Its setup, in place of the canned responses,
// takes `opted_out`, which maps a category key, or `$all`, to the recipients who opted out of it,
// `templates`, which maps a template_key that names a template made in PostHog to that template's category,
// or null for a template with no category, and `storage`, an optional host:port for signed attachment uploads.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException($error, $errno);
}
$stateFile = $argv[1] . '.state';
$setup = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$optedOut = $setup['opted_out'] ?? [];
$templates = $setup['templates'] ?? [];
$storage = $setup['storage'] ?? null;
echo stream_socket_get_name($server, false) . "\n";
flush();

$uploadsRoute = 'POST /api/messaging/attachments/uploads/ HTTP/1.1';
$sendRoute = 'POST /api/messaging/send/email/ HTTP/1.1';
$codeSendFields = [
    'to', 'distinct_id', 'template_key', 'subject', 'html', 'text', 'from', 'reply_to', 'capture_engagement',
    'sensitive', 'tracking', 'category', 'cc', 'bcc', 'attachments',
];
$categoryFields = ['key', 'type', 'name', 'description'];
$categoryTypes = ['marketing', 'transactional'];

$state = ['categories' => [], 'sends' => []];
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

$hasClickableUnsubscribeLink = static function (string $html) use ($unsubscribeHref): bool {
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
        $isAnchor = strtolower(substr($html, $tagStart, $pos - $tagStart)) === 'a';
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
                if ($isAnchor && strtolower($name) === 'href' && preg_match($unsubscribeHref, $value) === 1) {
                    $matched = true;
                }
            } elseif ($name === '') {
                $pos++;
            }
        }
        if ($pos >= $len) {
            return false;
        }
        if ($matched) {
            return true;
        }
        $pos++;
    }

    return false;
};

$uploads = static function (string $body) use ($storage, $refuse): array {
    if ($storage === null) {
        return $refuse(404, 'not_found', 'Uploads not configured on this fake.');
    }
    $payload = json_decode($body, true);
    if (!is_array($payload) || !isset($payload['files']) || !is_array($payload['files']) || $payload['files'] === []) {
        return $refuse(400, 'invalid_request', 'Send 1 to 10 files.', 'files');
    }
    $answers = [];
    foreach ($payload['files'] as $file) {
        if (!is_array($file) || !is_string($file['sha256'] ?? null) || !is_int($file['size'] ?? null)) {
            return $refuse(400, 'invalid_request', 'Each file takes sha256 and size.', 'files');
        }
        $sha256 = $file['sha256'];
        $answers[] = [
            'sha256' => $sha256,
            'status' => 'upload',
            'url' => "http://$storage/attachments/team/2/$sha256?X-Amz-Signature=abc",
            'method' => 'PUT',
            'headers' => ['Content-Length' => (string) $file['size']],
            'url_expires_at' => '2026-11-02T12:15:00Z',
        ];
    }

    return [200, ['files' => $answers]];
};

$send = static function (
    string $requestLine,
    string $body,
    array &$state
) use (
    $uploadsRoute,
    $sendRoute,
    $codeSendFields,
    $categoryFields,
    $categoryTypes,
    $optedOut,
    $templates,
    $placeholder,
    $refuse,
    $isText,
    $hasClickableUnsubscribeLink,
    $uploads
): array {
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
        $trimmedKey = is_string($category['key'] ?? null) ? trim($category['key']) : null;
        $trimmedDescription = array_key_exists('description', $category) && is_string($category['description'])
            ? trim($category['description'])
            : null;
        $valid = is_array($category)
            && array_diff(array_keys($category), $categoryFields) === []
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
        if (!empty($email['attachments'])) {
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
    $messageId = sprintf('01a18356-c56c-0001-e494-%012d', count($state['sends']) + 1);
    $sendRecord = ['message_id' => $messageId, 'category' => $key, 'type' => $type];
    if (!empty($email['attachments'])) {
        $sendRecord['attachments'] = $email['attachments'];
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
    $length = (int) ($headers['content-length'] ?? 0);
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($connection, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Incomplete request body');
        }
        $body .= $chunk;
    }
    [$status, $reply] = $send($requestLine, $body, $state);
    file_put_contents($argv[1], json_encode([
        'requestLine' => $requestLine,
        'headers' => $headers,
        'body' => $body,
        'status' => $status,
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
    $reply = json_encode($reply, JSON_THROW_ON_ERROR);
    fwrite($connection, "HTTP/1.1 $status Response\r\nContent-Type: application/json\r\nContent-Length: "
        . strlen($reply) . "\r\nConnection: close\r\n\r\n" . $reply);
    fclose($connection);
}
