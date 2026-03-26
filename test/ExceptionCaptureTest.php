<?php

namespace PostHog\Test;

require_once 'test/error_log_mock.php';

use Exception;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\ExceptionCapture;
use PostHog\PostHog;

class ExceptionCaptureTest extends TestCase
{
    use ClockMockTrait;

    private const FAKE_API_KEY = "random_key";

    private MockedHttpClient $httpClient;
    private Client $client;

    public function setUp(): void
    {
        date_default_timezone_set("UTC");
        $this->httpClient = new MockedHttpClient("app.posthog.com");
        $this->client = new Client(
            self::FAKE_API_KEY,
            ["debug" => true],
            $this->httpClient,
            "test"
        );
        PostHog::init(null, null, $this->client);

        global $errorMessages;
        $errorMessages = [];
    }

    // -------------------------------------------------------------------------
    // ExceptionCapture unit tests
    // -------------------------------------------------------------------------

    public function testBuildParsedExceptionFromString(): void
    {
        $result = ExceptionCapture::buildParsedException('something went wrong');

        $this->assertIsArray($result);
        $this->assertEquals('Error', $result['type']);
        $this->assertEquals('something went wrong', $result['value']);
        $this->assertEquals(['type' => 'generic', 'handled' => true], $result['mechanism']);
        $this->assertNull($result['stacktrace']);
    }

    public function testBuildParsedExceptionFromThrowable(): void
    {
        $exception = new \RuntimeException('test error');
        $result = ExceptionCapture::buildParsedException($exception);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);

        $entry = $result[0];
        $this->assertEquals('RuntimeException', $entry['type']);
        $this->assertEquals('test error', $entry['value']);
        $this->assertEquals(['type' => 'generic', 'handled' => true], $entry['mechanism']);
    }

    public function testStacktraceFramesArePresent(): void
    {
        $exception = new \RuntimeException('with trace');
        $result = ExceptionCapture::buildParsedException($exception);

        $entry = $result[0];
        $this->assertNotNull($entry['stacktrace']);
        $this->assertEquals('raw', $entry['stacktrace']['type']);
        $this->assertNotEmpty($entry['stacktrace']['frames']);
    }

    public function testStacktraceFrameStructure(): void
    {
        $exception = new \RuntimeException('frame check');
        $result = ExceptionCapture::buildParsedException($exception);

        $frames = $result[0]['stacktrace']['frames'];
        $frame  = $frames[0];

        $this->assertArrayHasKey('filename', $frame);
        $this->assertArrayHasKey('abs_path', $frame);
        $this->assertArrayHasKey('lineno', $frame);
        $this->assertArrayHasKey('function', $frame);
        $this->assertArrayHasKey('in_app', $frame);
        $this->assertEquals('php', $frame['platform']);
    }

    public function testInAppFalseForVendorFrames(): void
    {
        // Simulate a vendor frame
        $reflector = new \ReflectionClass(ExceptionCapture::class);
        $method    = $reflector->getMethod('buildFrame');
        $method->setAccessible(true);

        $frame = $method->invoke(null, [
            'file'     => '/app/vendor/some/package/Foo.php',
            'line'     => 10,
            'function' => 'doSomething',
        ]);

        $this->assertFalse($frame['in_app']);
    }

    public function testInAppTrueForAppFrames(): void
    {
        $reflector = new \ReflectionClass(ExceptionCapture::class);
        $method    = $reflector->getMethod('buildFrame');
        $method->setAccessible(true);

        $frame = $method->invoke(null, [
            'file'     => '/app/src/Services/MyService.php',
            'line'     => 42,
            'function' => 'handle',
        ]);

        $this->assertTrue($frame['in_app']);
    }

    public function testChainedExceptionsProduceMultipleEntries(): void
    {
        $cause  = new \InvalidArgumentException('root cause');
        $outer  = new \RuntimeException('wrapped', 0, $cause);
        $result = ExceptionCapture::buildParsedException($outer);

        $this->assertCount(2, $result);
        // outermost first (unshift order)
        $this->assertEquals('InvalidArgumentException', $result[0]['type']);
        $this->assertEquals('RuntimeException', $result[1]['type']);
    }

    public function testReturnsNullForInvalidInput(): void
    {
        $result = ExceptionCapture::buildParsedException(42);
        $this->assertNull($result);
    }

    public function testContextLinesAddedForInAppFrames(): void
    {
        // Throw inside a helper so the test file appears in getTrace()
        $e = $this->throwHelper();
        $result = ExceptionCapture::buildParsedException($e);

        $frames = $result[0]['stacktrace']['frames'];
        // Any in-app frame whose source file is readable should have context_line
        $testFrames = array_filter($frames, fn($f) => isset($f['context_line']));
        $this->assertNotEmpty($testFrames, 'At least one in-app frame should have context_line');
    }

    public function testStacktraceUsesThrowableFileAndLineForMostRecentFrame(): void
    {
        [$exception, $throwLine] = $this->throwHelperWithKnownLine();
        $result = ExceptionCapture::buildParsedException($exception);

        $frames = $result[0]['stacktrace']['frames'];
        $frame = $frames[0];

        $this->assertEquals(__FILE__, $frame['abs_path']);
        $this->assertEquals($throwLine, $frame['lineno']);
    }

    public function testStacktracePreservesOriginalCallerFrame(): void
    {
        [$exception, $throwLine, $callerLine] = $this->nestedThrowHelperWithKnownLines();
        $result = ExceptionCapture::buildParsedException($exception);

        $frames = array_values($result[0]['stacktrace']['frames']);
        $innermostFrame = $frames[0];
        $callerFrame = $frames[1];

        $this->assertEquals(__FILE__, $innermostFrame['abs_path']);
        $this->assertEquals($throwLine, $innermostFrame['lineno']);
        $this->assertEquals(__FILE__, $callerFrame['abs_path']);
        $this->assertEquals($callerLine, $callerFrame['lineno']);
        $this->assertEquals(__CLASS__ . '->throwNestedHelper', $callerFrame['function']);
    }

    public function testInternalFunctionErrorDoesNotDuplicateTopFrame(): void
    {
        [$exception, $arraySumLine, $callerLine] = $this->internalErrorHelperWithKnownLines();
        $result = ExceptionCapture::buildParsedException($exception);

        $frames = array_values($result[0]['stacktrace']['frames']);

        $this->assertEquals('array_sum', $frames[0]['function']);
        $this->assertEquals(__FILE__, $frames[0]['abs_path']);
        $this->assertEquals($arraySumLine, $frames[0]['lineno']);
        $this->assertEquals(__CLASS__ . '->internalErrorLeaf', $frames[1]['function']);
        $this->assertEquals(__FILE__, $frames[1]['abs_path']);
        $this->assertEquals($callerLine, $frames[1]['lineno']);
        $this->assertNotEquals($frames[0], $frames[1]);
    }

    public function testStrictTypeErrorUsesCallsiteBeforeDeclaration(): void
    {
        $scriptPath = tempnam(sys_get_temp_dir(), 'posthog-type-error-');
        $this->assertNotFalse($scriptPath);

        $script = <<<'PHP'
<?php
declare(strict_types=1);

return (function () {
    $declarationLine = __LINE__ + 1;
    function requiresIntForTrace(int $value): int
    {
        return $value;
    }

    try {
        $callLine = __LINE__ + 1;
        requiresIntForTrace('nope');
    } catch (\Throwable $e) {
        return [$e, $callLine, $declarationLine];
    }

    return [null, 0, 0];
})();
PHP;

        file_put_contents($scriptPath, $script);

        try {
            [$exception, $callLine, $declarationLine] = require $scriptPath;
            $this->assertInstanceOf(\Throwable::class, $exception);

            $result = ExceptionCapture::buildParsedException($exception);
            $frames = array_values($result[0]['stacktrace']['frames']);

            $this->assertSame($scriptPath, $frames[0]['abs_path']);
            $this->assertSame('requiresIntForTrace', $frames[0]['function']);
            $this->assertSame($callLine, $frames[0]['lineno']);
            $this->assertNotSame($declarationLine, $frames[0]['lineno']);
        } finally {
            unlink($scriptPath);
        }
    }

    private function throwHelper(): \RuntimeException
    {
        try {
            throw new \RuntimeException('context test');
        } catch (\RuntimeException $e) {
            return $e;
        }
    }

    private function throwHelperWithKnownLine(): array
    {
        try {
            $throwLine = __LINE__ + 1;
            throw new \RuntimeException('known line');
        } catch (\RuntimeException $e) {
            return [$e, $throwLine];
        }
    }

    private function nestedThrowHelperWithKnownLines(): array
    {
        try {
            $throwLine = 0;
            $callerLine = __LINE__ + 1;
            $this->throwNestedHelper($throwLine);
        } catch (\RuntimeException $e) {
            return [$e, $throwLine, $callerLine];
        }
    }

    private function throwNestedHelper(int &$throwLine): never
    {
        $throwLine = __LINE__ + 1;
        throw new \RuntimeException('nested known line');
    }

    private function internalErrorHelperWithKnownLines(): array
    {
        try {
            $arraySumLine = 0;
            $callerLine = __LINE__ + 1;
            $this->internalErrorLeaf($arraySumLine);
        } catch (\TypeError $e) {
            return [$e, $arraySumLine, $callerLine];
        }
    }

    private function internalErrorLeaf(int &$arraySumLine): void
    {
        $arraySumLine = __LINE__ + 1;
        array_sum('not-an-array');
    }

    public function testFunctionIncludesClass(): void
    {
        $reflector = new \ReflectionClass(ExceptionCapture::class);
        $method    = $reflector->getMethod('buildFrame');
        $method->setAccessible(true);

        $frame = $method->invoke(null, [
            'file'     => '/app/src/Foo.php',
            'line'     => 1,
            'class'    => 'App\\Foo',
            'type'     => '->',
            'function' => 'bar',
        ]);

        $this->assertEquals('App\\Foo->bar', $frame['function']);
    }

    // -------------------------------------------------------------------------
    // Client::captureException integration tests
    // -------------------------------------------------------------------------

    public function testCaptureExceptionSendsExceptionEvent(): void
    {
        $this->executeAtFrozenDateTime(new \DateTime('2024-01-01'), function () {
            $exception = new \RuntimeException('boom');
            $result = $this->client->captureException($exception, 'user-123');

            $this->assertTrue($result);
            PostHog::flush();

            $batchCall = $this->findBatchCall();
            $this->assertNotNull($batchCall);

            $payload = json_decode($batchCall['payload'], true);
            $event   = $payload['batch'][0];

            $this->assertEquals('$exception', $event['event']);
            $this->assertEquals('user-123', $event['distinct_id']);
            $this->assertArrayHasKey('$exception_list', $event['properties']);
            $this->assertCount(1, $event['properties']['$exception_list']);
            $this->assertEquals('RuntimeException', $event['properties']['$exception_list'][0]['type']);
            $this->assertEquals('boom', $event['properties']['$exception_list'][0]['value']);
        });
    }

    public function testCaptureExceptionWithoutDistinctIdGeneratesUuidAndSetsNoProfile(): void
    {
        $this->client->captureException(new \Exception('anon error'));
        PostHog::flush();

        $batchCall = $this->findBatchCall();
        $payload   = json_decode($batchCall['payload'], true);
        $event     = $payload['batch'][0];

        // distinct_id should look like a UUID
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $event['distinct_id']
        );
        $this->assertFalse($event['properties']['$process_person_profile']);
    }

    public function testCaptureExceptionWithDistinctIdDoesNotSetNoProfile(): void
    {
        $this->client->captureException(new \Exception('known user'), 'user-456');
        PostHog::flush();

        $batchCall = $this->findBatchCall();
        $payload   = json_decode($batchCall['payload'], true);
        $event     = $payload['batch'][0];

        $this->assertEquals('user-456', $event['distinct_id']);
        $this->assertArrayNotHasKey('$process_person_profile', $event['properties']);
    }

    public function testCaptureExceptionMergesAdditionalProperties(): void
    {
        $this->client->captureException(
            new \Exception('ctx error'),
            'user-789',
            ['$current_url' => 'https://example.com', 'custom_key' => 'custom_value']
        );
        PostHog::flush();

        $batchCall = $this->findBatchCall();
        $payload   = json_decode($batchCall['payload'], true);
        $props     = $payload['batch'][0]['properties'];

        $this->assertEquals('https://example.com', $props['$current_url']);
        $this->assertEquals('custom_value', $props['custom_key']);
        $this->assertArrayHasKey('$exception_list', $props);
    }

    public function testCaptureExceptionFromString(): void
    {
        $this->client->captureException('a plain string error', 'user-str');
        PostHog::flush();

        $batchCall = $this->findBatchCall();
        $payload   = json_decode($batchCall['payload'], true);
        $props     = $payload['batch'][0]['properties'];

        $this->assertEquals('Error', $props['$exception_list'][0]['type']);
        $this->assertEquals('a plain string error', $props['$exception_list'][0]['value']);
    }

    public function testCaptureExceptionReturnsFalseForInvalidInput(): void
    {
        $result = $this->client->captureException(42);
        $this->assertFalse($result);
    }

    public function testCaptureExceptionPayloadStaysBelowLibCurlLimitForLargeSourceContext(): void
    {
        $scriptPath = tempnam(sys_get_temp_dir(), 'posthog-exception-');
        $this->assertNotFalse($scriptPath);

        $longLine = '$junk = \'' . str_repeat('x', 2000) . '\';';
        $script = <<<PHP
<?php

return (function () {
    function recurseForPayloadLimit(int \$n): void
    {
        $longLine
        if (\$n === 0) {
            throw new \\RuntimeException('boom');
        }

        recurseForPayloadLimit(\$n - 1);
    }

    try {
        recurseForPayloadLimit(45);
    } catch (\\Throwable \$e) {
        return \$e;
    }
})();
PHP;

        file_put_contents($scriptPath, $script);

        try {
            $exception = require $scriptPath;
            $this->assertInstanceOf(\Throwable::class, $exception);

            $exceptionList = ExceptionCapture::buildParsedException($exception);
            $payload = json_encode([
                'batch' => [[
                    'event' => '$exception',
                    'properties' => ['$exception_list' => $exceptionList],
                    'distinct_id' => 'user-123',
                    'library' => 'posthog-php',
                    'library_version' => PostHog::VERSION,
                    'library_consumer' => 'LibCurl',
                    'groups' => [],
                    'timestamp' => date('c'),
                    'type' => 'capture',
                ]],
                'api_key' => self::FAKE_API_KEY,
            ]);

            $this->assertNotFalse($payload);
            $this->assertLessThan(32 * 1024, strlen($payload));
        } finally {
            unlink($scriptPath);
        }
    }

    public function testPostHogFacadeCaptureException(): void
    {
        $result = PostHog::captureException(new \Exception('facade test'), 'facade-user');
        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function findBatchCall(): ?array
    {
        foreach ($this->httpClient->calls as $call) {
            if ($call['path'] === '/batch/') {
                return $call;
            }
        }
        return null;
    }
}
