<?php

namespace PostHog\Laravel\Tests\Fixtures;

use RuntimeException;

/**
 * Runs send-api-stub.php in its own process, so the real SDK sends over HTTP and a test reads what arrived.
 */
final class SendApiStub
{
    /** @var resource */
    private $process;
    private string $requestsFile;
    private string $address;

    /**
     * @param list<array{status: int, body?: string, headers?: array<string, string>}> $responses
     */
    public function __construct(array $responses)
    {
        $this->requestsFile = tempnam(sys_get_temp_dir(), 'posthog-send-');
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/send-api-stub.php', $this->requestsFile, json_encode($responses)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the send API stub.');
        }
        $this->process = $process;
        $address = fgets($pipes[1]);
        if ($address === false) {
            throw new RuntimeException('The send API stub did not start: ' . stream_get_contents($pipes[2]));
        }
        $this->address = trim($address);
    }

    public function url(): string
    {
        return 'http://' . $this->address;
    }

    /**
     * @return list<array{requestLine: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->requestsFile)));

        return array_values(array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines
        ));
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->requestsFile);
    }
}
