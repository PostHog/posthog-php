<?php

namespace PostHog\Test\Mailer;

use PostHog\EmailError;

/**
 * PostHog's send and upload routes and its file storage, in place of the network.
 *
 * The upload route answers `present` for a file storage holds and a signed PUT for any other. Storage takes a PUT
 * only as signed and only when the bytes match the signed checksum. The send route refuses an attachment storage
 * doesn't hold and click tracking without open tracking, as PostHog does, and queues one email per idempotency key.
 */
final class FakePostHog
{
    public const SECRET_KEY = 'phs_secret';
    public const MESSAGE_ID = '01a18356-c56c-0001-e494-7666bc915188';
    private const STORAGE = 'https://storage.example/team/2/';
    private const SIGNATURE = '?X-Amz-Signature=signed';

    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
    public array $requests = [];
    /** @var list<array{int, string, list<string>}|EmailError> the send route's next answers */
    public array $sendReplies = [];
    /** @var array<string, string> the files storage holds, by SHA-256 */
    public array $stored = [];
    /** @var list<array<string, mixed>> the emails PostHog queued, in order */
    public array $queued = [];
    /** @var array<string, array<string, string>> the headers storage signed for each upload URL */
    private array $signed = [];
    /** @var array<string, true> the idempotency keys of the queued emails */
    private array $keys = [];

    public function __construct(private readonly string $secretKey = self::SECRET_KEY)
    {
    }

    /**
     * @param list<string> $headers
     * @return array{int, string, list<string>}
     */
    public function __invoke(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutMs,
        int $connectTimeoutMs = 0
    ): array {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($method === 'PUT') {
            return $this->store($url, $headers, (string) $body);
        }
        if (self::header($headers, 'Authorization') !== 'Bearer ' . $this->secretKey) {
            return self::error(401, 'authentication_failed', null);
        }
        if (str_ends_with($url, '/messaging/attachments/uploads/')) {
            return $this->negotiate((string) $body);
        }

        return $this->send($headers, (string) $body);
    }

    /**
     * The sends that reached the send route, accepted or not, as their decoded bodies.
     *
     * @return list<array<string, mixed>>
     */
    public function sends(): array
    {
        $sends = array_filter(
            $this->requests,
            static fn(array $request): bool => str_ends_with($request['url'], '/messaging/send/email/')
        );

        return array_values(array_map(
            static fn(array $request): array => json_decode((string) $request['body'], true, 512, JSON_THROW_ON_ERROR),
            $sends
        ));
    }

    /**
     * The value of a header on the nth request to the send route.
     */
    public function sendHeader(string $name, int $index = 0): ?string
    {
        $sends = array_values(array_filter(
            $this->requests,
            static fn(array $request): bool => str_ends_with($request['url'], '/messaging/send/email/')
        ));

        return isset($sends[$index]) ? self::header($sends[$index]['headers'], $name) : null;
    }

    /** @return array{int, string, list<string>} */
    private function negotiate(string $body): array
    {
        $slots = [];
        foreach (json_decode($body, true)['files'] ?? [] as $file) {
            $sha256 = $file['sha256'];
            if (isset($this->stored[$sha256])) {
                $slots[] = ['sha256' => $sha256, 'status' => 'present', 'expires_at' => '2026-12-12T12:00:00Z'];
                continue;
            }
            $url = self::STORAGE . $sha256 . self::SIGNATURE;
            $this->signed[$url] = [
                'Content-Length' => (string) $file['size'],
                'x-amz-checksum-sha256' => base64_encode((string) hex2bin($sha256)),
            ];
            $slots[] = [
                'sha256' => $sha256,
                'status' => 'upload',
                'url' => $url,
                'method' => 'PUT',
                'headers' => $this->signed[$url],
                'url_expires_at' => '2026-11-13T12:15:00Z',
            ];
        }

        return [200, json_encode(['files' => $slots], JSON_THROW_ON_ERROR), []];
    }

    /**
     * @param list<string> $headers
     * @return array{int, string, list<string>}
     */
    private function store(string $url, array $headers, string $body): array
    {
        if (!isset($this->signed[$url])) {
            return [403, '<Error><Code>AccessDenied</Code></Error>', []];
        }
        foreach ($this->signed[$url] as $name => $value) {
            if (self::header($headers, $name) !== $value) {
                return [403, '<Error><Code>SignatureDoesNotMatch</Code></Error>', []];
            }
        }
        if (base64_encode(hash('sha256', $body, true)) !== $this->signed[$url]['x-amz-checksum-sha256']) {
            return [400, '<Error><Code>BadDigest</Code></Error>', []];
        }
        $this->stored[hash('sha256', $body)] = $body;

        return [200, '', []];
    }

    /**
     * @param list<string> $headers
     * @return array{int, string, list<string>}
     */
    private function send(array $headers, string $body): array
    {
        $email = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (($email['tracking']['clicks'] ?? null) === true && ($email['tracking']['opens'] ?? null) === false) {
            return self::error(400, 'clicks_need_opens', 'tracking');
        }
        foreach ($email['attachments'] ?? [] as $index => $attachment) {
            if (!isset($this->stored[$attachment['sha256'] ?? ''])) {
                return self::error(400, 'attachment_not_uploaded', "attachments__{$index}__sha256");
            }
        }
        $reply = array_shift($this->sendReplies)
            ?? [202, json_encode(['message_id' => self::MESSAGE_ID, 'replayed' => false]), []];
        if ($reply instanceof EmailError) {
            throw $reply;
        }
        $key = self::header($headers, 'Idempotency-Key') ?? '';
        if ($reply[0] >= 200 && $reply[0] < 300 && !isset($this->keys[$key])) {
            $this->keys[$key] = true;
            $this->queued[] = $email;
        }

        return $reply;
    }

    /** @return array{int, string, list<string>} */
    private static function error(int $status, string $code, ?string $attr): array
    {
        $error = ['type' => 'validation_error', 'code' => $code, 'detail' => "PostHog said $code.", 'attr' => $attr];

        return [$status, json_encode($error, JSON_THROW_ON_ERROR), []];
    }

    /** @param list<string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}
