<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\ExceptionCapture;

class ExceptionCaptureTest extends TestCase
{
    private const FAKE_API_KEY = "random_key";

    private MockedHttpClient $httpClient;
    private Client $client;

    public function setUp(): void
    {
        date_default_timezone_set("UTC");
        ExceptionCapture::resetForTests();

        global $errorMessages;
        $errorMessages = [];
    }

    public function tearDown(): void
    {
        ExceptionCapture::resetForTests();
    }

    public function testDisabledErrorTrackingDoesNotRegisterHandlers(): void
    {
        $previousExceptionHandler = static function (\Throwable $exception): void {
        };
        $previousErrorHandler = static function (int $errno, string $message, string $file, int $line): bool {
            return true;
        };

        set_exception_handler($previousExceptionHandler);
        set_error_handler($previousErrorHandler);

        try {
            $this->buildClient(['error_tracking' => ['enabled' => false]]);

            $this->assertFalse($this->getFlag('exceptionHandlerInstalled'));
            $this->assertFalse($this->getFlag('errorHandlerInstalled'));
            $this->assertSame($previousExceptionHandler, $this->getCurrentExceptionHandler());
            $this->assertSame($previousErrorHandler, $this->getCurrentErrorHandler());
        } finally {
            restore_exception_handler();
            restore_error_handler();
        }
    }

    public function testEnabledErrorTrackingRegistersHandlersOnce(): void
    {
        $previousExceptionHandler = static function (\Throwable $exception): void {
        };
        $previousErrorHandler = static function (int $errno, string $message, string $file, int $line): bool {
            return true;
        };

        set_exception_handler($previousExceptionHandler);
        set_error_handler($previousErrorHandler);

        try {
            $shutdownRegisteredBefore = $this->getFlag('shutdownHandlerRegistered');

            $this->buildClient(['error_tracking' => ['enabled' => true]]);
            $this->buildClient(['error_tracking' => ['enabled' => true]]);

            $this->assertTrue($this->getFlag('exceptionHandlerInstalled'));
            $this->assertTrue($this->getFlag('errorHandlerInstalled'));
            $this->assertSame(
                [ExceptionCapture::class, 'handleException'],
                $this->getCurrentExceptionHandler()
            );
            $this->assertSame(
                [ExceptionCapture::class, 'handleError'],
                $this->getCurrentErrorHandler()
            );
            $this->assertSame(
                $previousExceptionHandler,
                $this->getProperty('previousExceptionHandler')
            );
            $this->assertSame(
                $previousErrorHandler,
                $this->getProperty('previousErrorHandler')
            );
            $this->assertTrue($this->getFlag('shutdownHandlerRegistered'));
            $this->assertTrue(
                $shutdownRegisteredBefore || $this->getFlag('shutdownHandlerRegistered')
            );
        } finally {
            ExceptionCapture::resetForTests();
            restore_exception_handler();
            restore_error_handler();
        }
    }

    public function testExceptionHandlerCapturesFlushesAndChainsPreviousHandler(): void
    {
        $previousCalls = 0;
        $receivedException = null;

        $previousExceptionHandler = static function (
            \Throwable $exception
        ) use (
            &$previousCalls,
            &$receivedException
        ): void {
            $previousCalls++;
            $receivedException = $exception;
        };

        set_exception_handler($previousExceptionHandler);

        try {
            $this->buildClient(['error_tracking' => ['enabled' => true]]);

            $exception = new \RuntimeException('uncaught boom');
            ExceptionCapture::handleException($exception);

            $this->assertSame(1, $previousCalls);
            $this->assertSame($exception, $receivedException);

            $event = $this->findExceptionEvent();

            $this->assertSame('$exception', $event['event']);
            $this->assertFalse($event['properties']['$exception_handled']);
            $this->assertSame('php_exception_handler', $event['properties']['$exception_source']);
            $this->assertSame(
                ['type' => 'auto.exception_handler', 'handled' => false],
                $event['properties']['$exception_list'][0]['mechanism']
            );
            $this->assertSame('RuntimeException', $event['properties']['$exception_list'][0]['type']);
            $this->assertFalse($event['properties']['$process_person_profile']);
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $event['distinct_id']
            );
        } finally {
            ExceptionCapture::resetForTests();
            restore_exception_handler();
        }
    }

    public function testExceptionHandlerRethrowsWhenNoPreviousHandlerExists(): void
    {
        $this->buildClient(['error_tracking' => ['enabled' => true]]);
        $exception = new \RuntimeException('uncaught without previous');

        try {
            ExceptionCapture::handleException($exception);
            $this->fail('Expected the uncaught exception to be rethrown');
        } catch (\RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $event = $this->findExceptionEvent();
        $this->assertFalse($event['properties']['$exception_handled']);
        $this->assertSame('php_exception_handler', $event['properties']['$exception_source']);
    }

    public function testErrorHandlerCapturesNonFatalErrorsWithoutCaptureFrames(): void
    {
        $previousCalls = 0;
        $previousErrorHandler = static function (
            int $errno,
            string $message,
            string $file,
            int $line
        ) use (&$previousCalls): bool {
            $previousCalls++;
            return true;
        };

        set_error_handler($previousErrorHandler);
        $previousReporting = error_reporting();

        try {
            $this->buildClient(['error_tracking' => ['enabled' => true]]);
            error_reporting(E_ALL);

            $triggerLine = 0;
            $callSiteLine = __LINE__ + 1;
            $this->triggerWarningHelper($triggerLine);
            $this->client->flush();
            $event = $this->findExceptionEvent();
            $frames = $event['properties']['$exception_list'][0]['stacktrace']['frames'];

            $this->assertSame(1, $previousCalls);
            $this->assertTrue($event['properties']['$exception_handled']);
            $this->assertSame('php_error_handler', $event['properties']['$exception_source']);
            $this->assertSame(E_USER_WARNING, $event['properties']['$php_error_severity']);
            $this->assertSame(
                ['type' => 'auto.error_handler', 'handled' => true],
                $event['properties']['$exception_list'][0]['mechanism']
            );
            $this->assertSame('ErrorException', $event['properties']['$exception_list'][0]['type']);
            $this->assertSame('trigger_error', $frames[0]['function']);
            $this->assertSame(__FILE__, $frames[0]['abs_path']);
            $this->assertSame($triggerLine, $frames[0]['lineno']);
            $this->assertSame(__CLASS__ . '->triggerWarningHelper', $frames[1]['function']);
            $this->assertSame(__FILE__, $frames[1]['abs_path']);
            $this->assertSame($callSiteLine, $frames[1]['lineno']);
            $this->assertFalse($this->framesContainFunction($frames, ExceptionCapture::class . '::handleError'));
        } finally {
            error_reporting($previousReporting);
            ExceptionCapture::resetForTests();
            restore_error_handler();
        }
    }

    public function testErrorHandlerRespectsRuntimeSuppression(): void
    {
        $previousCalls = 0;
        $previousErrorHandler = static function (
            int $errno,
            string $message,
            string $file,
            int $line
        ) use (&$previousCalls): bool {
            $previousCalls++;
            return true;
        };

        set_error_handler($previousErrorHandler);
        $previousReporting = error_reporting();

        try {
            $this->buildClient(['error_tracking' => ['enabled' => true]]);

            error_reporting(0);
            $result = ExceptionCapture::handleError(E_USER_WARNING, 'suppressed', __FILE__, 321);

            $this->assertTrue($result);
            $this->assertSame(1, $previousCalls);
            $this->assertNull($this->findBatchCall());
        } finally {
            error_reporting($previousReporting);
            ExceptionCapture::resetForTests();
            restore_error_handler();
        }
    }

    public function testShutdownHandlerCapturesFatalsAndFlushes(): void
    {
        $this->buildClient(['error_tracking' => ['enabled' => true]]);

        ExceptionCapture::handleShutdown([
            'type' => E_ERROR,
            'message' => 'fatal boom',
            'file' => __FILE__,
            'line' => 456,
        ]);

        $event = $this->findExceptionEvent();
        $frames = $event['properties']['$exception_list'][0]['stacktrace']['frames'];

        $this->assertFalse($event['properties']['$exception_handled']);
        $this->assertSame('php_shutdown_handler', $event['properties']['$exception_source']);
        $this->assertSame(E_ERROR, $event['properties']['$php_error_severity']);
        $this->assertSame(
            ['type' => 'auto.shutdown_handler', 'handled' => false],
            $event['properties']['$exception_list'][0]['mechanism']
        );
        $this->assertCount(1, $frames);
        $this->assertSame(__FILE__, $frames[0]['abs_path']);
        $this->assertSame(456, $frames[0]['lineno']);
        $this->assertArrayNotHasKey('function', $frames[0]);
    }

    public function testFatalShutdownCaptureIsDeduplicatedAcrossErrorAndShutdownPaths(): void
    {
        $result = $this->runStandaloneScript(<<<'PHP'
set_error_handler(static function (int $errno, string $message, string $file, int $line): bool {
    return false;
});

$http = new \PostHog\Test\MockedHttpClient("app.posthog.com");
$client = new \PostHog\Client("key", ["debug" => true, "error_tracking" => ["enabled" => true]], $http, null, false);

\PostHog\ExceptionCapture::handleError(E_USER_ERROR, 'fatal dedupe', __FILE__, 789);
\PostHog\ExceptionCapture::handleShutdown([
    'type' => E_USER_ERROR,
    'message' => 'fatal dedupe',
    'file' => __FILE__,
    'line' => 789,
]);

echo json_encode(['calls' => $http->calls], JSON_THROW_ON_ERROR);
PHP);

        $this->assertCount(1, $result['calls']);
        $payload = json_decode($result['calls'][0]['payload'], true);
        $event = $payload['batch'][0];
        $this->assertSame('php_error_handler', $event['properties']['$exception_source']);
        $this->assertFalse($event['properties']['$exception_handled']);
    }

    public function testExcludedExceptionsSkipCapture(): void
    {
        $previousErrorHandler = static function (int $errno, string $message, string $file, int $line): bool {
            return true;
        };

        set_error_handler($previousErrorHandler);

        $this->buildClient([
            'error_tracking' => [
                'enabled' => true,
                'excluded_exceptions' => [\RuntimeException::class, \ErrorException::class],
            ],
        ]);

        try {
            try {
                ExceptionCapture::handleException(new \RuntimeException('skip me'));
                $this->fail('Expected the excluded uncaught exception to be rethrown');
            } catch (\RuntimeException $caught) {
                $this->assertSame('skip me', $caught->getMessage());
            }
            ExceptionCapture::handleError(E_USER_WARNING, 'skip warning', __FILE__, 987);
            $this->client->flush();

            $this->assertNull($this->findBatchCall());
        } finally {
            ExceptionCapture::resetForTests();
            restore_error_handler();
        }
    }

    public function testContextProviderCanSupplyDistinctIdAndProperties(): void
    {
        $providerPayload = null;

        $this->buildClient([
            'error_tracking' => [
                'enabled' => true,
                'context_provider' => static function (array $payload) use (&$providerPayload): array {
                    $providerPayload = $payload;

                    return [
                        'distinctId' => 'provider-user',
                        'properties' => [
                            '$current_url' => 'https://example.com/error',
                            'job_name' => 'sync-users',
                        ],
                    ];
                },
            ],
        ]);

        try {
            ExceptionCapture::handleException(new \RuntimeException('provider boom'));
            $this->fail('Expected the uncaught exception to be rethrown');
        } catch (\RuntimeException $caught) {
            $this->assertSame('provider boom', $caught->getMessage());
        }

        $event = $this->findExceptionEvent();

        $this->assertIsArray($providerPayload);
        $this->assertSame('exception_handler', $providerPayload['source']);
        $this->assertSame('provider-user', $event['distinct_id']);
        $this->assertSame('https://example.com/error', $event['properties']['$current_url']);
        $this->assertSame('sync-users', $event['properties']['job_name']);
        $this->assertArrayNotHasKey('$process_person_profile', $event['properties']);
    }

    public function testAutoCaptureOnlyOverridesPrimaryMechanismForChains(): void
    {
        $this->buildClient(['error_tracking' => ['enabled' => true]]);

        $exception = new \RuntimeException(
            'outer uncaught',
            0,
            new \InvalidArgumentException('inner cause')
        );

        try {
            ExceptionCapture::handleException($exception);
            $this->fail('Expected the uncaught exception to be rethrown');
        } catch (\RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $event = $this->findExceptionEvent();
        $exceptionList = $event['properties']['$exception_list'];

        $this->assertFalse($event['properties']['$exception_handled']);
        $this->assertSame('RuntimeException', $exceptionList[0]['type']);
        $this->assertSame(
            ['type' => 'auto.exception_handler', 'handled' => false],
            $exceptionList[0]['mechanism']
        );
        $this->assertSame('InvalidArgumentException', $exceptionList[1]['type']);
        $this->assertSame(
            ['type' => 'generic', 'handled' => true],
            $exceptionList[1]['mechanism']
        );
    }

    public function testLaterClientsDoNotStealInstalledAutoCaptureHandlers(): void
    {
        $firstHttpClient = new MockedHttpClient("app.posthog.com");
        $firstClient = new Client(
            'first-key',
            ['debug' => true, 'error_tracking' => ['enabled' => true]],
            $firstHttpClient,
            null,
            false
        );

        $secondHttpClient = new MockedHttpClient("eu.posthog.com");
        new Client(
            'second-key',
            ['debug' => true, 'error_tracking' => ['enabled' => true], 'host' => 'eu.posthog.com'],
            $secondHttpClient,
            null,
            false
        );

        try {
            ExceptionCapture::handleException(new \RuntimeException('owner stays first'));
            $this->fail('Expected the uncaught exception to be rethrown');
        } catch (\RuntimeException $caught) {
            $this->assertSame('owner stays first', $caught->getMessage());
        }

        $firstBatchCalls = array_values(array_filter(
            $firstHttpClient->calls ?? [],
            static fn(array $call): bool => $call['path'] === '/batch/'
        ));
        $secondBatchCalls = array_values(array_filter(
            $secondHttpClient->calls ?? [],
            static fn(array $call): bool => $call['path'] === '/batch/'
        ));

        $this->assertCount(1, $firstBatchCalls);
        $this->assertCount(0, $secondBatchCalls);

        $payload = json_decode($firstBatchCalls[0]['payload'], true);
        $this->assertSame('$exception', $payload['batch'][0]['event']);

        $firstClient->flush();
    }

    public function testWarningPromotedToErrorExceptionIsCapturedOnlyOnce(): void
    {
        $result = $this->runStandaloneScript(<<<'PHP'
set_error_handler(static function (int $errno, string $message, string $file, int $line): bool {
    throw new \ErrorException($message, 0, $errno, $file, $line);
});

$http = new \PostHog\Test\MockedHttpClient("app.posthog.com");
$client = new \PostHog\Client("key", ["debug" => true, "error_tracking" => ["enabled" => true]], $http, null, false);

try {
    \PostHog\ExceptionCapture::handleError(E_USER_WARNING, 'promoted warning', __FILE__, 612);
} catch (\Throwable $exception) {
    try {
        \PostHog\ExceptionCapture::handleException($exception);
    } catch (\Throwable $ignored) {
    }
}

$client->flush();
echo json_encode(['calls' => $http->calls], JSON_THROW_ON_ERROR);
PHP);

        $this->assertCount(1, $result['calls']);
        $payload = json_decode($result['calls'][0]['payload'], true);
        $event = $payload['batch'][0];
        $this->assertSame('php_error_handler', $event['properties']['$exception_source']);
        $this->assertFalse($event['properties']['$exception_handled']);
    }

    public function testUserErrorCanBeCapturedFromErrorHandlerWhenPreviousHandlerHandlesIt(): void
    {
        $result = $this->runStandaloneScript(<<<'PHP'
$previousCalls = 0;
set_error_handler(static function (int $errno, string $message, string $file, int $line) use (&$previousCalls): bool {
    $previousCalls++;
    return true;
});

$http = new \PostHog\Test\MockedHttpClient("app.posthog.com");
$client = new \PostHog\Client("key", ["debug" => true, "error_tracking" => ["enabled" => true]], $http, null, false);
$handled = \PostHog\ExceptionCapture::handleError(E_USER_ERROR, 'handled user fatal', __FILE__, 733);
$client->flush();

echo json_encode([
    'handled' => $handled,
    'previous_calls' => $previousCalls,
    'calls' => $http->calls,
], JSON_THROW_ON_ERROR);
PHP);

        $this->assertTrue($result['handled']);
        $this->assertSame(1, $result['previous_calls']);
        $this->assertCount(1, $result['calls']);

        $payload = json_decode($result['calls'][0]['payload'], true);
        $event = $payload['batch'][0];

        $this->assertSame('php_error_handler', $event['properties']['$exception_source']);
        $this->assertTrue($event['properties']['$exception_handled']);
        $this->assertSame(
            ['type' => 'auto.error_handler', 'handled' => true],
            $event['properties']['$exception_list'][0]['mechanism']
        );
    }

    private function buildClient(array $options): void
    {
        $this->httpClient = new MockedHttpClient("app.posthog.com");
        $this->client = new Client(
            self::FAKE_API_KEY,
            array_merge(['debug' => true], $options),
            $this->httpClient,
            null,
            false
        );
    }

    private function triggerWarningHelper(int &$triggerLine): void
    {
        $triggerLine = __LINE__ + 1;
        trigger_error('warn', E_USER_WARNING);
    }

    private function findBatchCall(): ?array
    {
        foreach ($this->httpClient->calls ?? [] as $call) {
            if ($call['path'] === '/batch/') {
                return $call;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function findExceptionEvent(): array
    {
        $batchCall = $this->findBatchCall();
        $this->assertNotNull($batchCall);

        $payload = json_decode($batchCall['payload'], true);
        $this->assertIsArray($payload);

        return $payload['batch'][0];
    }

    private function getCurrentExceptionHandler(): callable|null
    {
        $probe = static function (\Throwable $exception): void {
        };

        $current = set_exception_handler($probe);
        restore_exception_handler();

        return $current;
    }

    private function getCurrentErrorHandler(): callable|null
    {
        $probe = static function (int $errno, string $message, string $file, int $line): bool {
            return true;
        };

        $current = set_error_handler($probe);
        restore_error_handler();

        return $current;
    }

    private function getFlag(string $property): bool
    {
        return (bool) $this->getProperty($property);
    }

    private function getProperty(string $property): mixed
    {
        $reflection = new \ReflectionClass(ExceptionCapture::class);
        $propertyReflection = $reflection->getProperty($property);
        $propertyReflection->setAccessible(true);

        return $propertyReflection->getValue();
    }

    /**
     * @param array<int, array<string, mixed>> $frames
     */
    private function framesContainFunction(array $frames, string $function): bool
    {
        foreach ($frames as $frame) {
            if (($frame['function'] ?? null) === $function) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function runStandaloneScript(string $body): array
    {
        $scriptPath = tempnam(sys_get_temp_dir(), 'posthog-error-tracking-');
        $this->assertNotFalse($scriptPath);

        $autoloadPath = var_export(realpath(__DIR__ . '/../vendor/autoload.php'), true);
        $errorLogMockPath = var_export(realpath(__DIR__ . '/error_log_mock.php'), true);
        $mockedHttpClientPath = var_export(realpath(__DIR__ . '/MockedHttpClient.php'), true);

        $script = <<<PHP
<?php
require {$autoloadPath};
require {$errorLogMockPath};
require {$mockedHttpClientPath};

\PostHog\ExceptionCapture::resetForTests();
{$body}
PHP;

        file_put_contents($scriptPath, $script);

        try {
            $output = [];
            $exitCode = 0;

            exec(PHP_BINARY . ' ' . escapeshellarg($scriptPath), $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));

            $decoded = json_decode(implode("\n", $output), true);
            $this->assertIsArray($decoded);

            return $decoded;
        } finally {
            unlink($scriptPath);
        }
    }
}
