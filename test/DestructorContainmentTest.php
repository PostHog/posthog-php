<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\Consumer\File;
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
