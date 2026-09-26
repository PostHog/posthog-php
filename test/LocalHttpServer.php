<?php

namespace PostHog\Test;

use RuntimeException;

final class LocalHttpServer
{
    private $process;
    private string $requestsFile;
    private string $errorsFile;
    private string $address;

    public function __construct(array $responses = [])
    {
        $this->requestsFile = tempnam(sys_get_temp_dir(), 'posthog-requests-');
        $this->errorsFile = tempnam(sys_get_temp_dir(), 'posthog-server-');
        $this->process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/http-server.php', $this->requestsFile, json_encode($responses)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->errorsFile, 'a']],
            $pipes
        );
        if (!is_resource($this->process)) {
            $this->stop();
            throw new RuntimeException('Could not start local HTTP server');
        }
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 5);
        $this->address = trim(fgets($pipes[1]) ?: '');
        fclose($pipes[1]);
        if ($this->address === '') {
            $error = file_get_contents($this->errorsFile);
            $this->stop();
            throw new RuntimeException('Local HTTP server did not become ready: ' . $error);
        }
    }

    public function address(): string
    {
        return $this->address;
    }

    public function requests(): array
    {
        return array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($this->requestsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        );
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach ([$this->requestsFile, $this->errorsFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
