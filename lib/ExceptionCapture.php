<?php

namespace PostHog;

class ExceptionCapture
{
    private const SHUTDOWN_FATAL_ERROR_TYPES = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
    ];

    private const ERROR_HANDLER_DEFERRED_FATAL_TYPES = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
    ];

    private static ?Client $client = null;

    /** @var array<string, mixed> */
    private static array $options = [];

    private static bool $exceptionHandlerInstalled = false;
    private static bool $errorHandlerInstalled = false;
    private static bool $shutdownHandlerRegistered = false;
    // Auto-capture itself can fail or trigger warnings; guard against recursively capturing
    // PostHog's own error path.
    private static bool $isCapturing = false;

    /** @var callable|null */
    private static $previousExceptionHandler = null;

    /** @var callable|null */
    private static $previousErrorHandler = null;

    /** @var array<string, true> */
    private static array $fatalErrorSignatures = [];

    /** @var array<int, true> */
    private static array $delegatedErrorExceptionIds = [];

    /**
     * @param array<string, mixed> $config Contents of the 'error_tracking' options subkey.
     */
    public static function configure(Client $client, array $config): void
    {
        $normalized = self::normalizeOptions($config);

        if (!$normalized['enabled']) {
            return;
        }

        if (
            self::hasInstalledHandlers()
            && self::$client !== null
            && self::$client !== $client
        ) {
            return;
        }

        self::$client = $client;
        self::$options = $normalized;

        if (!self::$exceptionHandlerInstalled) {
            self::$previousExceptionHandler = set_exception_handler([self::class, 'handleException']);
            self::$exceptionHandlerInstalled = true;
        }

        if ($normalized['capture_errors'] && !self::$errorHandlerInstalled) {
            self::$previousErrorHandler = set_error_handler([self::class, 'handleError']);
            self::$errorHandlerInstalled = true;
        }

        if ($normalized['capture_errors'] && !self::$shutdownHandlerRegistered) {
            register_shutdown_function([self::class, 'handleShutdown']);
            self::$shutdownHandlerRegistered = true;
        }
    }

    public static function handleException(\Throwable $exception): void
    {
        if (self::consumeDelegatedErrorException($exception)) {
            self::finishUnhandledException($exception);
            return;
        }

        if (!self::shouldCapture()) {
            self::finishUnhandledException($exception);
            return;
        }

        if (!self::shouldCaptureThrowable($exception)) {
            self::finishUnhandledException($exception);
            return;
        }

        self::captureUncaughtException($exception);
        self::flushSafely();
        self::finishUnhandledException($exception);
    }

    public static function handleError(
        int $errno,
        string $message,
        string $file = '',
        int $line = 0
    ): bool {
        if (!self::shouldCaptureErrors()) {
            return self::delegateError($errno, $message, $file, $line);
        }

        if (($errno & error_reporting()) === 0) {
            return self::delegateError($errno, $message, $file, $line);
        }

        if (in_array($errno, self::ERROR_HANDLER_DEFERRED_FATAL_TYPES, true)) {
            // Fatal errors are handled from shutdown so we can flush at process end and avoid
            // double-sending the same failure from both the error and shutdown handlers.
            return self::delegateError($errno, $message, $file, $line);
        }

        $maxFrames = self::$options['max_frames'] ?? 20;
        $exception = new \ErrorException($message, 0, $errno, $file, $line);
        $exceptionEntry = ExceptionPayloadBuilder::buildFromTrace(
            $exception,
            self::normalizeErrorHandlerTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)),
            $maxFrames
        );

        try {
            $delegated = self::delegateError($errno, $message, $file, $line);
        } catch (\Throwable $delegatedException) {
            if (
                self::matchesErrorException(
                    $delegatedException,
                    $errno,
                    $message,
                    $file,
                    $line
                )
                && self::shouldCaptureThrowable($exception)
            ) {
                self::rememberDelegatedErrorException($delegatedException);
                self::captureErrorException(
                    $exception,
                    $errno,
                    'error_handler',
                    'php_error_handler',
                    ['type' => 'auto.error_handler', 'handled' => false],
                    [$exceptionEntry]
                );

                if ($errno === E_USER_ERROR) {
                    self::rememberFatalError($errno, $message, $file, $line);
                    self::flushSafely();
                }
            }

            throw $delegatedException;
        }

        $handled = $errno === E_USER_ERROR ? $delegated : true;

        if (self::shouldCaptureThrowable($exception)) {
            self::captureErrorException(
                $exception,
                $errno,
                'error_handler',
                'php_error_handler',
                ['type' => 'auto.error_handler', 'handled' => $handled],
                [$exceptionEntry]
            );

            if (!$handled && $errno === E_USER_ERROR) {
                self::rememberFatalError($errno, $message, $file, $line);
                self::flushSafely();
            }
        }

        return $delegated;
    }

    /**
     * @param array<string, mixed>|null $lastError
     */
    public static function handleShutdown(?array $lastError = null): void
    {
        if (!self::shouldCaptureErrors()) {
            return;
        }

        $lastError = $lastError ?? error_get_last();
        if (!is_array($lastError)) {
            return;
        }

        $severity = $lastError['type'] ?? null;
        if (!is_int($severity) || !in_array($severity, self::SHUTDOWN_FATAL_ERROR_TYPES, true)) {
            return;
        }

        $message = is_string($lastError['message'] ?? null) ? $lastError['message'] : '';
        $file = is_string($lastError['file'] ?? null) ? $lastError['file'] : '';
        $line = is_int($lastError['line'] ?? null) ? $lastError['line'] : 0;

        if (self::isDuplicateFatalError($severity, $message, $file, $line)) {
            return;
        }

        $exception = new \ErrorException($message, 0, $severity, $file, $line);

        if (!self::shouldCaptureThrowable($exception)) {
            return;
        }

        self::rememberFatalError($severity, $message, $file, $line);

        $maxFrames = self::$options['max_frames'] ?? 20;
        // error_get_last() gives us location data but not a useful application backtrace. Build a
        // single location frame instead of using a fresh ErrorException trace, which would only
        // show handleShutdown().
        $exceptionEntry = ExceptionPayloadBuilder::buildFromLocation(
            \ErrorException::class,
            $message,
            $file !== '' ? $file : null,
            $line !== 0 ? $line : null,
            $maxFrames
        );

        self::captureErrorException(
            $exception,
            $severity,
            'shutdown_handler',
            'php_shutdown_handler',
            ['type' => 'auto.shutdown_handler', 'handled' => false],
            [$exceptionEntry]
        );

        self::flushSafely();
    }

    public static function resetForTests(): void
    {
        if (self::$exceptionHandlerInstalled) {
            restore_exception_handler();
            self::$exceptionHandlerInstalled = false;
        }

        if (self::$errorHandlerInstalled) {
            restore_error_handler();
            self::$errorHandlerInstalled = false;
        }

        self::$client = null;
        self::$options = self::normalizeOptions([]);
        self::$isCapturing = false;
        self::$previousExceptionHandler = null;
        self::$previousErrorHandler = null;
        self::$fatalErrorSignatures = [];
        self::$delegatedErrorExceptionIds = [];
    }

    private static function shouldCapture(): bool
    {
        return self::$options['enabled'] && self::$client !== null;
    }

    private static function shouldCaptureErrors(): bool
    {
        return self::shouldCapture() && self::$options['capture_errors'];
    }

    private static function shouldCaptureThrowable(\Throwable $exception): bool
    {
        foreach (self::$options['excluded_exceptions'] as $excludedClass) {
            if ($exception instanceof $excludedClass) {
                return false;
            }
        }

        return true;
    }

    private static function callPreviousExceptionHandler(\Throwable $exception): bool
    {
        if (is_callable(self::$previousExceptionHandler)) {
            call_user_func(self::$previousExceptionHandler, $exception);
            return true;
        }

        return false;
    }

    private static function delegateError(
        int $errno,
        string $message,
        string $file,
        int $line
    ): bool {
        if (is_callable(self::$previousErrorHandler)) {
            return (bool) call_user_func(
                self::$previousErrorHandler,
                $errno,
                $message,
                $file,
                $line
            );
        }

        return false;
    }

    private static function captureUncaughtException(\Throwable $exception): void
    {
        $maxFrames = self::$options['max_frames'] ?? 20;
        $exceptionList = ExceptionPayloadBuilder::buildExceptionList($exception, $maxFrames);
        $exceptionList = ExceptionPayloadBuilder::overridePrimaryMechanism($exceptionList, [
            'type' => 'auto.exception_handler',
            'handled' => false,
        ]);

        self::sendExceptionEvent($exception, 'exception_handler', 'php_exception_handler', $exceptionList);
    }

    /**
     * @param array[] $exceptionList Exception entries wrapped in an array.
     */
    private static function captureErrorException(
        \Throwable $exception,
        int $severity,
        string $contextSource,
        string $eventSource,
        array $mechanism,
        array $exceptionList
    ): void {
        $exceptionList = ExceptionPayloadBuilder::overridePrimaryMechanism($exceptionList, $mechanism);
        self::sendExceptionEvent($exception, $contextSource, $eventSource, $exceptionList, $severity);
    }

    /**
     * Common capture path shared by captureUncaughtException and captureErrorException.
     *
     * @param array[] $exceptionList
     */
    private static function sendExceptionEvent(
        \Throwable $exception,
        string $contextSource,
        string $eventSource,
        array $exceptionList,
        ?int $severity = null
    ): void {
        if (self::$client === null || self::$isCapturing) {
            return;
        }

        self::$isCapturing = true;

        try {
            $providerContext = self::getProviderContext([
                'source' => $contextSource,
                'exception' => $exception,
                'severity' => $severity,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            $properties = [
                '$exception_list' => $exceptionList,
                '$exception_handled' => ExceptionPayloadBuilder::getPrimaryHandled($exceptionList),
                '$exception_source' => $eventSource,
            ];

            if ($severity !== null) {
                $properties['$php_error_severity'] = $severity;
            }

            $properties = array_merge($providerContext['properties'], $properties);

            $distinctId = $providerContext['distinctId'];
            if ($distinctId === null) {
                $distinctId = Uuid::v4();
                $properties['$process_person_profile'] = false;
            }

            self::$client->capture([
                'distinctId' => $distinctId,
                'event' => '$exception',
                'properties' => $properties,
            ]);
        } catch (\Throwable $captureError) {
            // Ignore auto-capture failures to avoid interfering with app error handling.
        } finally {
            self::$isCapturing = false;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $trace
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeErrorHandlerTrace(array $trace): array
    {
        while (!empty($trace)) {
            $frame = $trace[0];
            $class = $frame['class'] ?? null;
            $function = $frame['function'] ?? null;

            if ($class === self::class || $function === 'handleError') {
                // debug_backtrace() starts inside the active error handler. Drop our own
                // frames so the first frame shown to PostHog is the user callsite/trigger_error().
                array_shift($trace);
                continue;
            }

            break;
        }

        return array_values(array_map(function (array $frame): array {
            return array_filter([
                'file' => is_string($frame['file'] ?? null) ? $frame['file'] : null,
                'line' => is_int($frame['line'] ?? null) ? $frame['line'] : null,
                'class' => is_string($frame['class'] ?? null) ? $frame['class'] : null,
                'type' => is_string($frame['type'] ?? null) ? $frame['type'] : null,
                'function' => is_string($frame['function'] ?? null) ? $frame['function'] : null,
            ], static fn($value) => $value !== null);
        }, $trace));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{distinctId: ?string, properties: array}
     */
    private static function getProviderContext(array $payload): array
    {
        $provider = self::$options['context_provider'];
        if (!is_callable($provider)) {
            return ['distinctId' => null, 'properties' => []];
        }

        try {
            $result = $provider($payload);
        } catch (\Throwable $providerError) {
            return ['distinctId' => null, 'properties' => []];
        }

        if (!is_array($result)) {
            return ['distinctId' => null, 'properties' => []];
        }

        $distinctId = $result['distinctId'] ?? null;
        if ($distinctId !== null && !is_scalar($distinctId)) {
            $distinctId = null;
        }

        $properties = $result['properties'] ?? [];
        if (!is_array($properties)) {
            $properties = [];
        }

        return [
            'distinctId' => $distinctId !== null && $distinctId !== '' ? (string) $distinctId : null,
            'properties' => $properties,
        ];
    }

    private static function flushSafely(): void
    {
        if (self::$client === null) {
            return;
        }

        try {
            self::$client->flush();
        } catch (\Throwable $flushError) {
            // Ignore flush failures during auto-capture.
        }
    }

    private static function isDuplicateFatalError(
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        $signature = self::errorSignature($severity, $message, $file, $line);
        return isset(self::$fatalErrorSignatures[$signature]);
    }

    private static function consumeDelegatedErrorException(\Throwable $exception): bool
    {
        $exceptionId = spl_object_id($exception);

        if (!isset(self::$delegatedErrorExceptionIds[$exceptionId])) {
            return false;
        }

        unset(self::$delegatedErrorExceptionIds[$exceptionId]);

        return true;
    }

    private static function rememberFatalError(
        int $severity,
        string $message,
        string $file,
        int $line
    ): void {
        $signature = self::errorSignature($severity, $message, $file, $line);
        self::$fatalErrorSignatures[$signature] = true;
    }

    private static function rememberDelegatedErrorException(\Throwable $exception): void
    {
        self::$delegatedErrorExceptionIds[spl_object_id($exception)] = true;
    }

    private static function errorSignature(
        int $severity,
        string $message,
        string $file,
        int $line
    ): string {
        return implode('|', [$severity, $file, $line, $message]);
    }

    private static function matchesErrorException(
        \Throwable $exception,
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        return $exception instanceof \ErrorException
            && $exception->getSeverity() === $severity
            && $exception->getMessage() === $message
            && $exception->getFile() === $file
            && $exception->getLine() === $line;
    }

    private static function hasInstalledHandlers(): bool
    {
        return self::$exceptionHandlerInstalled
            || self::$errorHandlerInstalled
            || self::$shutdownHandlerRegistered;
    }

    private static function finishUnhandledException(\Throwable $exception): void
    {
        if (self::callPreviousExceptionHandler($exception)) {
            return;
        }

        restore_exception_handler();
        throw $exception;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeOptions(array $config): array
    {
        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'capture_errors' => (bool) ($config['capture_errors'] ?? true),
            'excluded_exceptions' => array_values(array_filter(
                is_array($config['excluded_exceptions'] ?? null) ? $config['excluded_exceptions'] : [],
                fn($class) => is_string($class) && $class !== ''
            )),
            'max_frames' => max(0, (int) ($config['max_frames'] ?? 20)),
            'context_provider' => is_callable($config['context_provider'] ?? null)
                ? $config['context_provider']
                : null,
        ];
    }
}
