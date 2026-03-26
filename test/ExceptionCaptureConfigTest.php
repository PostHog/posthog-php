<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\ExceptionCapture;

class ExceptionCaptureConfigTest extends TestCase
{
    public function tearDown(): void
    {
        ExceptionCapture::configure([]);
    }

    public function testSourceContextCanBeDisabled(): void
    {
        ExceptionCapture::configure(['error_tracking_include_source_context' => false]);

        $exception = $this->throwHelper();
        $exceptionList = ExceptionCapture::normalizeExceptionList(
            ExceptionCapture::buildParsedException($exception)
        );

        $framesWithContext = array_filter(
            $exceptionList[0]['stacktrace']['frames'],
            static fn(array $frame): bool => isset($frame['context_line'])
        );

        $this->assertSame([], array_values($framesWithContext));
    }

    public function testContextLineWindowCanBeConfigured(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'posthog-context-lines-');
        $this->assertNotFalse($path);

        file_put_contents($path, implode("\n", [
            '<?php',
            'first();',
            'second();',
            'third();',
            'fourth();',
            'fifth();',
        ]) . "\n");

        try {
            ExceptionCapture::configure([
                'error_tracking_context_lines' => 1,
            ]);

            $frame = $this->buildFrame($path, 4);

            $this->assertSame('third();', $frame['context_line']);
            $this->assertSame(['second();'], $frame['pre_context']);
            $this->assertSame(['fourth();'], $frame['post_context']);
        } finally {
            unlink($path);
        }
    }

    public function testFrameLimitCanBeConfigured(): void
    {
        ExceptionCapture::configure([
            'error_tracking_max_frames' => 2,
        ]);

        $exceptionList = ExceptionCapture::normalizeExceptionList(
            ExceptionCapture::buildParsedException($this->nestedExceptionHelper(4))
        );

        $frames = $exceptionList[0]['stacktrace']['frames'];

        $this->assertCount(2, $frames);
        $this->assertArrayHasKey('context_line', $frames[0]);
        $this->assertArrayHasKey('context_line', $frames[1]);
    }

    private function throwHelper(): \RuntimeException
    {
        try {
            throw new \RuntimeException('context config');
        } catch (\RuntimeException $exception) {
            return $exception;
        }
    }

    private function nestedExceptionHelper(int $depth): \RuntimeException
    {
        try {
            $this->nestedThrow($depth);
        } catch (\RuntimeException $exception) {
            return $exception;
        }
    }

    private function nestedThrow(int $depth): never
    {
        if ($depth === 0) {
            throw new \RuntimeException('depth reached');
        }

        $this->nestedThrow($depth - 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFrame(string $path, int $line): array
    {
        $reflection = new \ReflectionClass(ExceptionCapture::class);
        $method = $reflection->getMethod('buildFrame');
        $method->setAccessible(true);

        return $method->invoke(
            null,
            [
                'file' => $path,
                'line' => $line,
                'function' => 'demo',
            ]
        );
    }
}
