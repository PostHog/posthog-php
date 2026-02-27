<?php

namespace PostHog;

class ExceptionUtils
{
    /**
     * Build the $exception_list payload from a Throwable.
     *
     * Walks the getPrevious() chain to produce a flat list
     * (outermost first, root cause last).
     *
     * @param \Throwable $exception
     * @param int $sourceContextLines Number of source lines to include around each frame (0 to disable)
     * @return array
     */
    public static function buildExceptionList(\Throwable $exception, int $sourceContextLines = 5): array
    {
        // Collect all exceptions in the chain (outermost first)
        $exceptions = [];
        $current = $exception;
        while ($current !== null) {
            $exceptions[] = $current;
            $current = $current->getPrevious();
        }

        $isChained = count($exceptions) > 1;
        $result = [];

        foreach ($exceptions as $index => $exc) {
            $entry = [
                'type' => get_class($exc),
                'value' => $exc->getMessage(),
                'mechanism' => [
                    'type' => $isChained && $index > 0 ? 'chained' : 'generic',
                    'handled' => true,
                ],
                'stacktrace' => [
                    'type' => 'raw',
                    'frames' => self::buildFrames($exc, $sourceContextLines),
                ],
            ];

            if ($isChained) {
                $entry['mechanism']['exception_id'] = $index;
                if ($index > 0) {
                    $entry['mechanism']['parent_id'] = $index - 1;
                    $entry['mechanism']['source'] = 'getPrevious()';
                }
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Build frames from a Throwable's trace.
     *
     * Returns frames in oldest-first order. Appends the throw location
     * as the final frame since PHP's getTrace() omits it.
     *
     * @param \Throwable $exception
     * @param int $sourceContextLines
     * @return array
     */
    public static function buildFrames(\Throwable $exception, int $sourceContextLines): array
    {
        $trace = $exception->getTrace();
        $frames = [];

        // getTrace() returns newest-first; reverse to get oldest-first
        foreach (array_reverse($trace) as $traceEntry) {
            $frames[] = self::buildFrame($traceEntry, $sourceContextLines);
        }

        // Append the throw location as the final frame
        if ($exception->getFile()) {
            $frames[] = self::buildFrame([
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ], $sourceContextLines);
        }

        return $frames;
    }

    /**
     * Build a single frame from a trace entry.
     *
     * @param array $frame A single trace entry from getTrace() or a synthetic entry
     * @param int $sourceContextLines
     * @return array
     */
    public static function buildFrame(array $frame, int $sourceContextLines): array
    {
        $filename = $frame['file'] ?? '[internal]';
        $lineno = $frame['line'] ?? 0;

        // Build function name, prefixing with class if present
        $function = $frame['function'] ?? null;
        if (!empty($frame['class'])) {
            $type = $frame['type'] ?? '->'; // -> for instance, :: for static
            $function = $frame['class'] . $type . $function;
        }

        $result = [
            'filename' => $filename,
            'lineno' => $lineno,
            'platform' => 'php',
            'in_app' => self::isInApp($filename),
        ];

        if ($function !== null) {
            $result['function'] = $function;
        }

        // Add source context if enabled and file is readable
        if ($sourceContextLines > 0 && $filename !== '[internal]' && $lineno > 0) {
            $lines = @file($filename);
            if ($lines !== false) {
                $lineIndex = $lineno - 1; // Convert to 0-indexed

                if (isset($lines[$lineIndex])) {
                    $result['context_line'] = rtrim($lines[$lineIndex], "\r\n");
                }

                // pre_context
                $preStart = max(0, $lineIndex - $sourceContextLines);
                $preContext = [];
                for ($i = $preStart; $i < $lineIndex; $i++) {
                    if (isset($lines[$i])) {
                        $preContext[] = rtrim($lines[$i], "\r\n");
                    }
                }
                $result['pre_context'] = $preContext;

                // post_context
                $postEnd = min(count($lines), $lineIndex + 1 + $sourceContextLines);
                $postContext = [];
                for ($i = $lineIndex + 1; $i < $postEnd; $i++) {
                    if (isset($lines[$i])) {
                        $postContext[] = rtrim($lines[$i], "\r\n");
                    }
                }
                $result['post_context'] = $postContext;
            }
        }

        return $result;
    }

    /**
     * Determine if a frame is "in app" code.
     *
     * Returns false for [internal] and paths containing /vendor/.
     *
     * @param string $filename
     * @return bool
     */
    public static function isInApp(string $filename): bool
    {
        if ($filename === '[internal]') {
            return false;
        }

        if (str_contains($filename, '/vendor/')) {
            return false;
        }

        return true;
    }
}
