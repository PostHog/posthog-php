<?php

namespace PostHog;

class ExceptionCapture
{
    private static bool $includeSourceContext = true;
    private static int $contextLines = 5;
    private static int $maxFrames = 50;
    private static int $maxContextFrames = 3;
    private static int $maxContextLineLength = 200;

    public static function configure(array $options = []): void
    {
        self::$includeSourceContext = (bool) ($options['error_tracking_include_source_context'] ?? true);
        self::$contextLines = max(0, (int) ($options['error_tracking_context_lines'] ?? 5));
        self::$maxFrames = max(0, (int) ($options['error_tracking_max_frames'] ?? 50));
        self::$maxContextFrames = max(0, (int) ($options['error_tracking_max_context_frames'] ?? 3));
        self::$maxContextLineLength = max(0, (int) ($options['error_tracking_max_context_line_length'] ?? 200));
    }

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
                $chain[] = self::buildThrowableException($current);
                $current = $current->getPrevious();
            }

            return $chain;
        }

        return null;
    }

    public static function buildThrowableExceptionFromTrace(\Throwable $exception, array $trace): array
    {
        return self::buildSingleException(
            get_class($exception),
            $exception->getMessage(),
            $trace
        );
    }

    public static function buildExceptionFromTrace(string $type, string $message, array $trace): array
    {
        return self::buildSingleException($type, $message, $trace);
    }

    public static function buildExceptionFromLocation(
        string $type,
        string $message,
        ?string $file,
        ?int $line
    ): array {
        $trace = null;

        if ($file !== null || $line !== null) {
            $trace = [[
                'file' => $file,
                'line' => $line,
            ]];
        }

        return self::buildSingleException($type, $message, $trace);
    }

    public static function normalizeExceptionList(array $exceptionList): array
    {
        if (isset($exceptionList['type'])) {
            return [$exceptionList];
        }

        return $exceptionList;
    }

    public static function overrideMechanism(array $exceptionList, array $mechanism): array
    {
        return array_map(function (array $exception) use ($mechanism) {
            $exception['mechanism'] = array_merge($exception['mechanism'] ?? [], $mechanism);
            return $exception;
        }, self::normalizeExceptionList($exceptionList));
    }

    public static function overridePrimaryMechanism(array $exceptionList, array $mechanism): array
    {
        $exceptionList = self::normalizeExceptionList($exceptionList);
        if (!isset($exceptionList[0]) || !is_array($exceptionList[0])) {
            return $exceptionList;
        }

        $exceptionList[0]['mechanism'] = array_merge($exceptionList[0]['mechanism'] ?? [], $mechanism);

        return $exceptionList;
    }

    public static function getPrimaryHandled(array $exceptionList): bool
    {
        $exceptionList = self::normalizeExceptionList($exceptionList);

        return (bool) (($exceptionList[0]['mechanism']['handled'] ?? false) === true);
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
        $contextFramesRemaining = self::$maxContextFrames;

        foreach (array_slice($trace, 0, self::$maxFrames) as $frame) {
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

        if (
            self::$includeSourceContext
            && $includeContext
            && $inApp
            && $absPath !== null
            && $lineno !== null
        ) {
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

            $preStart = max(0, $idx - self::$contextLines);
            if ($preStart < $idx) {
                $frame['pre_context'] = array_map(
                    [self::class, 'truncateContextLine'],
                    array_slice($lines, $preStart, $idx - $preStart)
                );
            }

            $postEnd = min($total, $idx + self::$contextLines + 1);
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
        if (self::$maxContextLineLength <= 0) {
            return '';
        }

        if (strlen($line) <= self::$maxContextLineLength) {
            return $line;
        }

        if (self::$maxContextLineLength <= 3) {
            return substr($line, 0, self::$maxContextLineLength);
        }

        return substr($line, 0, self::$maxContextLineLength - 3) . '...';
    }
}
