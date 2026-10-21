<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Consumer\LibCurl;
use ReflectionProperty;

class ConsumerCurlTimeoutTest extends TestCase
{
    public static function stalledTransports(): array
    {
        return [
            'libcurl' => ['LibCurl', false, true],
            'forkcurl synchronous' => ['ForkCurl', true, true],
            'forkcurl spawned' => ['ForkCurl', false, true],
        ];
    }

    #[DataProvider('stalledTransports')]
    public function testConfiguredTimeoutClosesStalledConnection(string $transport, bool $debug, bool $verify): void
    {
        $this->runReceiver($transport, ['timeout' => 50, 'debug' => $debug,
            'verify_batch_events_request' => $verify], false);
    }

    public function testLibCurlWithoutVerificationDoesNotWaitEvenWithZeroTimeout(): void
    {
        $this->runReceiver('LibCurl', ['timeout' => 0, 'debug' => false,
            'verify_batch_events_request' => false], false);
    }

    public static function zeroTimeoutTransports(): array
    {
        return ['libcurl' => ['LibCurl'], 'forkcurl' => ['ForkCurl']];
    }

    #[DataProvider('zeroTimeoutTransports')]
    public function testZeroTimeoutAllowsDelayedResponse(string $transport): void
    {
        $this->runReceiver($transport, ['timeout' => 0, 'debug' => true], true);
    }

    public function testLibCurlDefaultTimeoutIsTenSeconds(): void
    {
        $consumer = new LibCurl('test-key');
        $client = (new ReflectionProperty(LibCurl::class, 'httpClient'))->getValue($consumer);
        self::assertSame(10000, (new ReflectionProperty($client, 'curlTimeoutMilliseconds'))->getValue($client));
    }

    public static function forkCurlTimeoutArguments(): array
    {
        return [
            'default' => [[], '10'],
            'milliseconds' => [['timeout' => 50], '0.05'],
            'fractional seconds' => [['timeout' => 1250], '1.25'],
            'unlimited' => [['timeout' => 0], '0'],
        ];
    }

    #[DataProvider('forkCurlTimeoutArguments')]
    public function testForkCurlSetsConnectAndTotalTimeoutArguments(array $options, string $expected): void
    {
        $directory = sys_get_temp_dir() . '/posthog-curl-timeout-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $arguments = $directory . '/arguments';
        file_put_contents($directory . '/curl', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($arguments));
        chmod($directory . '/curl', 0700);
        $process = null;
        try {
            $process = $this->startConsumer('ForkCurl', array_merge($options, ['debug' => true]), $pipes, $directory);
            self::assertTrue($this->waitForExit($process), 'ForkCurl command exceeded the watchdog');
            self::assertSame('true', trim(stream_get_contents($pipes[1])));
            $args = file($arguments, FILE_IGNORE_NEW_LINES);
            foreach (['--connect-timeout', '--max-time'] as $argument) {
                $index = array_search($argument, $args, true);
                self::assertNotFalse($index, 'Missing ' . $argument);
                self::assertSame($expected, $args[$index + 1]);
            }
        } finally {
            $this->stopProcess($process, $pipes ?? []);
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function runReceiver(string $transport, array $options, bool $respond): void
    {
        $server = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/curl-timeout-receiver.php', $respond ? 'respond' : 'stall'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']],
            $serverPipes
        );
        self::assertIsResource($server);
        $process = null;
        try {
            stream_set_timeout($serverPipes[1], 5);
            $address = trim(fgets($serverPipes[1]));
            self::assertNotSame('', $address, 'Timeout receiver did not become ready');
            $options['host'] = $address;
            $process = $this->startConsumer($transport, $options, $pipes);
            if (($options['verify_batch_events_request'] ?? true) === false) {
                self::assertTrue($this->waitForExit($process), 'Unverified request exceeded the watchdog');
                self::assertSame('"retryable_failure"', trim(stream_get_contents($pipes[1])));
                return;
            }
            self::assertSame("received\n", fgets($serverPipes[1]), 'Receiver must observe the request');
            stream_set_blocking($serverPipes[1], false);
            $output = '';
            $deadline = microtime(true) + 3;
            do {
                $output .= stream_get_contents($serverPipes[1]);
                if (strpos($output, "closed\n") !== false) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertStringContainsString("closed\n", $output, 'Connection exceeded the three-second watchdog');
            self::assertTrue($this->waitForExit($process), 'Consumer exceeded the three-second watchdog');
            $result = trim(stream_get_contents($pipes[1]));
            $expected = $respond || ($transport === 'ForkCurl' && !$options['debug'])
                ? 'true' : '"retryable_failure"';
            self::assertSame($expected, $result);
        } finally {
            $this->stopProcess($server, $serverPipes);
            $this->stopProcess($process, $pipes ?? []);
        }
    }

    private function startConsumer(string $transport, array $options, ?array &$pipes, ?string $path = null)
    {
        $options = array_merge(['ssl' => false, 'maximum_backoff_duration' => 100], $options);
        $script = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';';
        if ($path !== null) {
            $script .= 'putenv(' . var_export('PATH=' . $path . ':' . getenv('PATH'), true) . ');';
        }
        $script .= '$consumer = new \\PostHog\\Consumer\\' . $transport . '("test-key", '
            . var_export($options, true) . ');'
            . 'echo json_encode($consumer->flushBatch([["event" => "timeout", "distinct_id" => "user"]]));';
        return proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']],
            $pipes
        );
    }

    private function waitForExit($process): bool
    {
        $deadline = microtime(true) + 3;
        do {
            if (!proc_get_status($process)['running']) {
                return true;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function stopProcess($process, array $pipes): void
    {
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }
}
