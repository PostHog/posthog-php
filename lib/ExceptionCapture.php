<?php

namespace PostHog;

class ExceptionCapture
{
    private const CONTEXT_LINES = 5;
    private const MAX_FRAMES = 50;
    private const MAX_CONTEXT_FRAMES = 3;
    private const MAX_CONTEXT_LINE_LENGTH = 200;

    /**
     * Build a parsed exception array from a Throwable or string.
     *
     * @param \Throwable|string $exception
     * @return array|null
     */
    public static function buildParsedException($exception): ?array
    {
        if (is_string($exception)) {
            return self::buildSingleException('Error', $exception, null);
        }

        if ($exception instanceof \Throwable) {
            $chain = [];
            $current = $exception;

            while ($current !== null) {
                array_unshift($chain, self::buildThrowableException($current));
                $current = $current->getPrevious();
            }

            return $chain;
        }

        return null;
    }

    private static function buildThrowableException(\Throwable $exception): array
    {
        return self::buildSingleException(
            get_class($exception),
            $exception->getMessage(),
            self::normalizeThrowableTrace($exception)
        );
    }

    private static function normalizeThrowableTrace(\Throwable $exception): array
    {
        $trace = $exception->getTrace();

        if (empty($trace)) {
            return [[
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]];
        }

        $firstFrameMatchesThrowSite =
            ($trace[0]['file'] ?? null) === $exception->getFile()
            && ($trace[0]['line'] ?? null) === $exception->getLine();

        if (!$firstFrameMatchesThrowSite) {
            array_unshift($trace, array_filter([
                'file'     => $exception->getFile(),
                'line'     => $exception->getLine(),
                'class'    => $trace[0]['class'] ?? null,
                'type'     => $trace[0]['type'] ?? null,
                'function' => $trace[0]['function'] ?? null,
            ], fn($value) => $value !== null));
        }

        return $trace;
    }

    private static function buildSingleException(string $type, string $message, ?array $trace): array
    {
        return [
            'type'      => $type,
            'value'     => $message,
            'mechanism' => [
                'type'    => 'generic',
                'handled' => true,
            ],
            'stacktrace' => self::buildStacktrace($trace),
        ];
    }

    private static function buildStacktrace(?array $trace): ?array
    {
        if (empty($trace)) {
            return null;
        }

        $frames = [];
        $contextFramesRemaining = self::MAX_CONTEXT_FRAMES;

        foreach (array_slice($trace, 0, self::MAX_FRAMES) as $frame) {
            $builtFrame = self::buildFrame($frame, $contextFramesRemaining > 0);
            if ($builtFrame === null) {
                continue;
            }

            if (isset($builtFrame['context_line'])) {
                $contextFramesRemaining--;
            }

            $frames[] = $builtFrame;
        }

        $frames = array_values(array_filter($frames));

        return [
            'type'   => 'raw',
            'frames' => $frames,
        ];
    }

    private static function buildFrame(array $frame, bool $includeContext = true): ?array
    {
        // getTrace() frames may lack file/line (e.g. internal PHP calls)
        $absPath  = $frame['file'] ?? null;
        $lineno   = $frame['line'] ?? null;
        $function = self::formatFunction($frame);
        $inApp    = $absPath !== null && !self::isVendorPath($absPath);

        $result = array_filter([
            'filename' => $absPath !== null ? basename($absPath) : null,
            'abs_path' => $absPath,
            'lineno'   => $lineno,
            'function' => $function,
            'in_app'   => $inApp,
            'platform' => 'php',
        ], fn($value) => $value !== null);

        if ($includeContext && $inApp && $absPath !== null && $lineno !== null) {
            self::addContextLines($result, $absPath, $lineno);
        }

        return $result;
    }

    private static function formatFunction(array $frame): ?string
    {
        $function = $frame['function'] ?? null;
        if ($function === null) {
            return null;
        }

        if (isset($frame['class'])) {
            $type = $frame['type'] ?? '::';
            return $frame['class'] . $type . $function;
        }

        return $function;
    }

    private static function isVendorPath(string $path): bool
    {
        return str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            || str_contains($path, '/vendor/');
    }

    private static function addContextLines(array &$frame, string $filePath, int $lineno): void
    {
        try {
            if (!is_readable($filePath)) {
                return;
            }

            $lines = file($filePath, FILE_IGNORE_NEW_LINES);
            if ($lines === false || empty($lines)) {
                return;
            }

            $total = count($lines);
            $idx   = $lineno - 1; // 0-based

            if ($idx < 0 || $idx >= $total) {
                return;
            }

            $frame['context_line'] = self::truncateContextLine($lines[$idx]);

            $preStart = max(0, $idx - self::CONTEXT_LINES);
            if ($preStart < $idx) {
                $frame['pre_context'] = array_map(
                    [self::class, 'truncateContextLine'],
                    array_slice($lines, $preStart, $idx - $preStart)
                );
            }

            $postEnd = min($total, $idx + self::CONTEXT_LINES + 1);
            if ($postEnd > $idx + 1) {
                $frame['post_context'] = array_map(
                    [self::class, 'truncateContextLine'],
                    array_slice($lines, $idx + 1, $postEnd - $idx - 1)
                );
            }
        } catch (\Throwable $e) {
            // Silently ignore file read errors
        }
    }

    private static function truncateContextLine(string $line): string
    {
        if (strlen($line) <= self::MAX_CONTEXT_LINE_LENGTH) {
            return $line;
        }

        return substr($line, 0, self::MAX_CONTEXT_LINE_LENGTH - 3) . '...';
    }
}
