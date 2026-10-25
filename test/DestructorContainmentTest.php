<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\Consumer\File;
use PostHog\HttpClient;
use PostHog\HttpResponse;
use ReflectionProperty;
use RuntimeException;

class DestructorContainmentTest extends TestCase
{
    #[DataProvider('automaticDestructionCases')]
    public function testAutomaticDestructionContainsApplicationThrowable(
        string $target,
        string $failure,
        string $throwableClass
    ): void {
        $output = $this->runScript($target, $failure, $throwableClass, 'automatic');

        $this->assertStringContainsString("application-finished\n", $output);
        $this->assertStringContainsString(
            $failure === 'serializer' ? 'serialization-attempted' : 'error-handler-called',
            $output
        );
        if ($target === 'client') {
            $this->assertSame(1, substr_count($output, 'cache-cleaned'));
        }
    }

    public static function automaticDestructionCases(): iterable
    {
        foreach (['client', 'consumer'] as $target) {
            foreach (['handler', 'serializer'] as $failure) {
                foreach (['RuntimeException', 'Error'] as $throwableClass) {
                    yield "$target-$failure-$throwableClass" => [$target, $failure, $throwableClass];
                }
            }
        }
    }

    #[DataProvider('explicitCallCases')]
    public function testExplicitCallsStillPropagateApplicationThrowable(
        string $target,
        string $failure,
        string $throwableClass,
        string $operation
    ): void {
        $output = $this->runScript($target, $failure, $throwableClass, $operation);

        $this->assertStringContainsString("exception-propagated\n", $output);
        $this->assertStringContainsString("application-finished\n", $output);
        if ($target === 'client') {
            $this->assertSame(1, substr_count($output, 'cache-cleaned'));
        }
        if ($operation === 'shutdown') {
            $this->assertLessThan(strpos($output, 'exception-propagated'), strpos($output, 'cache-cleaned'));
            $this->assertStringContainsString("shutdown-complete\n", $output);
        }
    }

    public static function explicitCallCases(): iterable
    {
        foreach (self::automaticDestructionCases() as $name => $case) {
            yield "$name-flush" => [...$case, 'flush'];
            if ($case[0] === 'client') {
                yield "$name-shutdown" => [...$case, 'shutdown'];
            }
        }
    }

    public static function shutdownRetryCases(): iterable
    {
        foreach (self::automaticDestructionCases() as $name => [$target, $failure, $throwableClass]) {
            if ($target === 'client') {
                yield $name => [$failure, $throwableClass];
            }
        }
    }

    #[DataProvider('shutdownRetryCases')]
    public function testExplicitShutdownRetryPropagatesApplicationThrowable(
        string $failure,
        string $throwableClass
    ): void {
        $output = $this->runScript('client', $failure, $throwableClass, 'shutdown-retry');

        $this->assertStringContainsString("exception-propagated\n", $output);
        $this->assertSame(1, substr_count($output, 'cache-cleaned'));
        $this->assertLessThan(strpos($output, 'exception-propagated'), strpos($output, 'cache-cleaned'));
        $this->assertStringContainsString("shutdown-complete\n", $output);
    }

    #[DataProvider('shutdownRetryCases')]
    public function testAutomaticShutdownRetryContainsApplicationThrowable(
        string $failure,
        string $throwableClass
    ): void {
        $output = $this->runScript('client', $failure, $throwableClass, 'automatic-retry');

        $this->assertStringContainsString("application-finished\n", $output);
        $this->assertSame(1, substr_count($output, 'cache-cleaned'));
        $marker = $failure === 'serializer' ? 'serialization-attempted' : 'error-handler-called';
        $this->assertGreaterThanOrEqual(2, substr_count($output, $marker));
    }

    public static function retryResults(): array
    {
        return ['retry succeeds' => [true], 'retry fails' => [false]];
    }

    #[DataProvider('retryResults')]
    public function testShutdownPreservesRetryCountAndFirstFlushResult(bool $retrySucceeds): void
    {
        $http = new class ($retrySucceeds) extends HttpClient {
            public int $attempts = 0;

            public function __construct(private bool $retrySucceeds)
            {
            }

            public function sendRequest(
                string $path,
                ?string $payload,
                array $extraHeaders = [],
                array $requestOptions = []
            ): HttpResponse {
                ++$this->attempts;
                if ($this->attempts > 2 || ($this->attempts === 2 && $this->retrySucceeds)) {
                    return new HttpResponse('{}', 200);
                }
                return new HttpResponse(false, 0, null, CURLE_COULDNT_CONNECT);
            }
        };
        $client = new Client('test-key', [], $http, loadFeatureFlags: false);
        $client->capture(['event' => 'shutdown retry', 'distinctId' => 'user']);

        $this->assertFalse($client->shutdown());
        $this->assertSame(2, $http->attempts);
        $this->assertTrue($client->shutdown());
        $this->assertSame(2, $http->attempts);
    }

    public function testShutdownPreservesTheInitialFlushException(): void
    {
        $first = new RuntimeException('initial flush failure');
        $http = new class ($first) extends HttpClient {
            private bool $attempted = false;

            public function __construct(private RuntimeException $first)
            {
            }

            public function sendRequest(
                string $path,
                ?string $payload,
                array $extraHeaders = [],
                array $requestOptions = []
            ): HttpResponse {
                if (!$this->attempted) {
                    $this->attempted = true;
                    throw $this->first;
                }
                throw new RuntimeException('later flush failure');
            }
        };
        $client = new Client('test-key', [], $http, loadFeatureFlags: false);
        $client->capture(['event' => 'shutdown failure', 'distinctId' => 'user']);

        try {
            $client->shutdown();
            $this->fail('Explicit shutdown must propagate the initial failure');
        } catch (RuntimeException $caught) {
            $this->assertSame($first, $caught);
        }
        $this->assertTrue($client->shutdown());
    }

    public function testShutdownClosesFileResourceEvenWhenFlushThrows(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'posthog-destructor-');
        $client = new class ('test-key', ['consumer' => 'file', 'filename' => $filename]) extends Client {
            public function flush()
            {
                throw new RuntimeException('flush failure');
            }
        };
        $consumer = (new ReflectionProperty(Client::class, 'consumer'))->getValue($client);
        $handle = (new ReflectionProperty(File::class, 'file_handle'))->getValue($consumer);
        $this->assertIsResource($handle);

        try {
            try {
                $client->shutdown();
                $this->fail('Explicit shutdown must propagate the flush failure');
            } catch (RuntimeException $exception) {
                $this->assertSame('flush failure', $exception->getMessage());
            }
            $this->assertFalse(is_resource($handle));
            $this->assertTrue($client->shutdown());
        } finally {
            unlink($filename);
        }
    }

    private function runScript(string $target, string $failure, string $throwableClass, string $operation): string
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/destructor-containment.php',
                $target,
                $failure,
                $throwableClass,
                $operation,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $output . $errors);
        $this->assertStringNotContainsString('Fatal error', $errors);

        return $output;
    }
}
