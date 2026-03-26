<?php

namespace PostHog\Test;

require_once 'test/error_log_mock.php';

use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\ErrorTrackingRegistrar;

class ErrorTrackingRegistrarTest extends TestCase
{
    private const FAKE_API_KEY = "random_key";

    private MockedHttpClient $httpClient;
    private Client $client;

    public function setUp(): void
    {
        date_default_timezone_set("UTC");
        ErrorTrackingRegistrar::resetForTests();

        global $errorMessages;
        $errorMessages = [];
    }

    public function tearDown(): void
    {
        ErrorTrackingRegistrar::resetForTests();
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
            $this->buildClient(['enable_error_tracking' => false]);

            $this->assertFalse($this->getRegistrarFlag('exceptionHandlerInstalled'));
            $this->assertFalse($this->getRegistrarFlag('errorHandlerInstalled'));
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
            $shutdownRegisteredBefore = $this->getRegistrarFlag('shutdownHandlerRegistered');

            $this->buildClient(['enable_error_tracking' => true]);
            $this->buildClient(['enable_error_tracking' => true]);

            $this->assertTrue($this->getRegistrarFlag('exceptionHandlerInstalled'));
            $this->assertTrue($this->getRegistrarFlag('errorHandlerInstalled'));
            $this->assertSame(
                [ErrorTrackingRegistrar::class, 'handleException'],
                $this->getCurrentExceptionHandler()
            );
            $this->assertSame(
                [ErrorTrackingRegistrar::class, 'handleError'],
                $this->getCurrentErrorHandler()
            );
            $this->assertSame(
                $previousExceptionHandler,
                $this->getRegistrarProperty('previousExceptionHandler')
            );
            $this->assertSame(
                $previousErrorHandler,
                $this->getRegistrarProperty('previousErrorHandler')
            );
            $this->assertTrue($this->getRegistrarFlag('shutdownHandlerRegistered'));
            $this->assertTrue(
                $shutdownRegisteredBefore || $this->getRegistrarFlag('shutdownHandlerRegistered')
            );
        } finally {
            ErrorTrackingRegistrar::resetForTests();
            restore_exception_handler();
            restore_error_handler();
        }
    }

    public function testExceptionHandlerCapturesFlushesAndChainsPreviousHandler(): void
    {
        $previousCalls = 0;
        $receivedException = null;

        $previousExceptionHandler = static function (\Throwable $exception) use (&$previousCalls, &$receivedException): void {
            $previousCalls++;
            $receivedException = $exception;
        };

        set_exception_handler($previousExceptionHandler);

        try {
            $this->buildClient(['enable_error_tracking' => true]);

            $exception = new \RuntimeException('uncaught boom');
            ErrorTrackingRegistrar::handleException($exception);

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
            ErrorTrackingRegistrar::resetForTests();
            restore_exception_handler();
        }
    }

    public function testErrorHandlerCapturesNonFatalErrorsWithoutRegistrarFrames(): void
    {
        $previousCalls = 0;
        $previousErrorHandler = static function (int $errno, string $message, string $file, int $line) use (&$previousCalls): bool {
            $previousCalls++;
            return true;
        };

        set_error_handler($previousErrorHandler);
        $previousReporting = error_reporting();

        try {
            $this->buildClient(['enable_error_tracking' => true]);
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
            $this->assertFalse($this->framesContainFunction($frames, ErrorTrackingRegistrar::class . '::handleError'));
        } finally {
            error_reporting($previousReporting);
            ErrorTrackingRegistrar::resetForTests();
            restore_error_handler();
        }
    }

    public function testErrorHandlerRespectsRuntimeSuppression(): void
    {
        $previousCalls = 0;
        $previousErrorHandler = static function (int $errno, string $message, string $file, int $line) use (&$previousCalls): bool {
            $previousCalls++;
            return true;
        };

        set_error_handler($previousErrorHandler);
        $previousReporting = error_reporting();

        try {
            $this->buildClient(['enable_error_tracking' => true]);

            error_reporting(0);
            $result = ErrorTrackingRegistrar::handleError(E_USER_WARNING, 'suppressed', __FILE__, 321);

            $this->assertTrue($result);
            $this->assertSame(1, $previousCalls);
            $this->assertNull($this->findBatchCall());
        } finally {
            error_reporting($previousReporting);
            ErrorTrackingRegistrar::resetForTests();
            restore_error_handler();
        }
    }

    public function testShutdownHandlerCapturesFatalsAndFlushes(): void
    {
        $this->buildClient(['enable_error_tracking' => true]);

        ErrorTrackingRegistrar::handleShutdown([
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
        $previousErrorHandler = static function (int $errno, string $message, string $file, int $line): bool {
            return true;
        };

        set_error_handler($previousErrorHandler);

        try {
            $this->buildClient(['enable_error_tracking' => true]);

            ErrorTrackingRegistrar::handleError(E_USER_ERROR, 'fatal dedupe', __FILE__, 789);
            ErrorTrackingRegistrar::handleShutdown([
                'type' => E_USER_ERROR,
                'message' => 'fatal dedupe',
                'file' => __FILE__,
                'line' => 789,
            ]);

            $batchCalls = $this->findBatchCalls();
            $this->assertCount(1, $batchCalls);

            $payload = json_decode($batchCalls[0]['payload'], true);
            $event = $payload['batch'][0];
            $this->assertSame('php_shutdown_handler', $event['properties']['$exception_source']);
        } finally {
            ErrorTrackingRegistrar::resetForTests();
            restore_error_handler();
        }
    }

    public function testExcludedExceptionsSkipThrowableAndGeneratedErrorExceptionCapture(): void
    {
        $this->buildClient([
            'enable_error_tracking' => true,
            'excluded_exceptions' => [\RuntimeException::class, \ErrorException::class],
        ]);

        ErrorTrackingRegistrar::handleException(new \RuntimeException('skip me'));
        ErrorTrackingRegistrar::handleError(E_USER_WARNING, 'skip warning', __FILE__, 987);
        $this->client->flush();

        $this->assertNull($this->findBatchCall());
    }

    public function testContextProviderCanSupplyDistinctIdAndProperties(): void
    {
        $providerPayload = null;

        $this->buildClient([
            'enable_error_tracking' => true,
            'error_tracking_context_provider' => static function (array $payload) use (&$providerPayload): array {
                $providerPayload = $payload;

                return [
                    'distinctId' => 'provider-user',
                    'properties' => [
                        '$current_url' => 'https://example.com/error',
                        'job_name' => 'sync-users',
                    ],
                ];
            },
        ]);

        ErrorTrackingRegistrar::handleException(new \RuntimeException('provider boom'));

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
        $this->buildClient(['enable_error_tracking' => true]);

        $exception = new \RuntimeException(
            'outer uncaught',
            0,
            new \InvalidArgumentException('inner cause')
        );

        ErrorTrackingRegistrar::handleException($exception);

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
     * @return array<int, array<string, mixed>>
     */
    private function findBatchCalls(): array
    {
        return array_values(array_filter(
            $this->httpClient->calls ?? [],
            static fn(array $call): bool => $call['path'] === '/batch/'
        ));
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

    private function getRegistrarFlag(string $property): bool
    {
        return (bool) $this->getRegistrarProperty($property);
    }

    private function getRegistrarProperty(string $property): mixed
    {
        $reflection = new \ReflectionClass(ErrorTrackingRegistrar::class);
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
}
