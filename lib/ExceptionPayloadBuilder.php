<?php

namespace PostHog;

class ExceptionPayloadBuilder
{
    private const CONTEXT_LINES = 5;
    private const DEFAULT_MAX_FRAMES = 20;

    /**
     * Build a parsed exception list from a Throwable or string.
     *
     * Always returns an array of exception entries (outermost first).
     *
     * @param \Throwable|string $exception
     * @param int $maxFrames
     * @return array[]
     */
    public static function buildExceptionList(
        \Throwable|string $exception,
        int $maxFrames = self::DEFAULT_MAX_FRAMES
    ): array {
        if (is_string($exception)) {
            return [self::buildSingleException('Error', $exception, null, $maxFrames)];
        }

        if ($exception instanceof \Throwable) {
            $chain = [];
            $current = $exception;

            while ($current !== null) {
                $chain[] = self::buildThrowableException($current, $maxFrames);
                $current = $current->getPrevious();
            }

            return $chain;
        }

        return [];
    }

    /**
     * Build a single exception entry from a Throwable using a custom trace.
     */
    public static function buildFromTrace(
        \Throwable $exception,
        array $trace,
        int $maxFrames = self::DEFAULT_MAX_FRAMES
    ): array {
        return self::buildSingleException(
            get_class($exception),
            $exception->getMessage(),
            $trace,
            $maxFrames
        );
    }

    /**
     * Build a single exception entry from type, message, and file/line location.
     */
    public static function buildFromLocation(
        string $type,
        string $message,
        ?string $file,
        ?int $line,
        int $maxFrames = self::DEFAULT_MAX_FRAMES
    ): array {
        $trace = null;

        if ($file !== null || $line !== null) {
            $trace = [[
                'file' => $file,
                'line' => $line,
            ]];
        }

        return self::buildSingleException($type, $message, $trace, $maxFrames);
    }

    /**
     * Override the mechanism on the primary (first) exception in the list.
     *
     * @param array[] $exceptionList
     * @param array $mechanism
     * @return array[]
     */
    public static function overridePrimaryMechanism(array $exceptionList, array $mechanism): array
    {
        if (!isset($exceptionList[0]) || !is_array($exceptionList[0])) {
            return $exceptionList;
        }

        $exceptionList[0]['mechanism'] = array_merge($exceptionList[0]['mechanism'] ?? [], $mechanism);

        return $exceptionList;
    }

    /**
     * Get the handled flag from the primary (first) exception.
     *
     * @param array[] $exceptionList
     */
    public static function getPrimaryHandled(array $exceptionList): bool
    {
        return (bool) (($exceptionList[0]['mechanism']['handled'] ?? false) === true);
    }

    private static function buildThrowableException(\Throwable $exception, int $maxFrames): array
    {
        return self::buildSingleException(
            get_class($exception),
            $exception->getMessage(),
            self::normalizeThrowableTrace($exception),
            $maxFrames
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

        if (
            !$firstFrameMatchesThrowSite
            && !self::isDeclarationLineForFirstFrame($exception, $trace[0])
        ) {
            // Many PHP exceptions report the throw site in getFile()/getLine() but omit it
            // from getTrace()[0]. Prepending a synthetic top frame keeps the first frame aligned
            // with the highlighted source location in PostHog.
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

    private static function isDeclarationLineForFirstFrame(\Throwable $exception, array $firstFrame): bool
    {
        $function = $firstFrame['function'] ?? null;
        $file = $exception->getFile();
        $line = $exception->getLine();

        if (!is_string($function) || $function === '' || $file === '' || $line <= 0 || !is_readable($file)) {
            return false;
        }

        try {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false || !isset($lines[$line - 1])) {
                return false;
            }

            $sourceLine = trim($lines[$line - 1]);
            if ($sourceLine === '') {
                return false;
            }

            // Strict-types TypeErrors often point getFile()/getLine() at the callee declaration,
            // while the trace already contains the real callsite as frame[0]. If we prepend a
            // synthetic frame here, the stack looks reversed: declaration first, callsite second.
            return (bool) preg_match(
                '/\bfunction\b[^(]*\b' . preg_quote($function, '/') . '\s*\(/',
                $sourceLine
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function buildSingleException(string $type, string $message, ?array $trace, int $maxFrames): array
    {
        return [
            'type'      => $type,
            'value'     => $message,
            'mechanism' => [
                'type'    => 'generic',
                'handled' => true,
            ],
            'stacktrace' => self::buildStacktrace($trace, $maxFrames),
        ];
    }

    private static function buildStacktrace(?array $trace, int $maxFrames): ?array
    {
        if (empty($trace)) {
            return null;
        }

        $frames = [];

        foreach (array_slice($trace, 0, $maxFrames) as $frame) {
            $builtFrame = self::buildFrame($frame);
            if ($builtFrame === null) {
                continue;
            }

            $frames[] = $builtFrame;
        }

        $frames = array_values(array_filter($frames));

        return [
            'type'   => 'raw',
            'frames' => $frames,
        ];
    }

    private static function buildFrame(array $frame): ?array
    {
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

        if ($inApp && $absPath !== null && $lineno !== null) {
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

            $frame['context_line'] = $lines[$idx];

            $preStart = max(0, $idx - self::CONTEXT_LINES);
            if ($preStart < $idx) {
                $frame['pre_context'] = array_slice($lines, $preStart, $idx - $preStart);
            }

            $postEnd = min($total, $idx + self::CONTEXT_LINES + 1);
            if ($postEnd > $idx + 1) {
                $frame['post_context'] = array_slice($lines, $idx + 1, $postEnd - $idx - 1);
            }
        } catch (\Throwable $e) {
            // Silently ignore file read errors
        }
    }
}
