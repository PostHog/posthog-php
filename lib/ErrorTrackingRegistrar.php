<?php

namespace PostHog;

class ErrorTrackingRegistrar
{
    private const FATAL_ERROR_TYPES = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
    ];

    private static ?Client $client = null;

    /** @var array<string, mixed> */
    private static array $options = [
        'enable_error_tracking' => false,
        'capture_uncaught_exceptions' => true,
        'capture_errors' => true,
        'capture_fatal_errors' => true,
        'error_reporting_mask' => E_ALL,
        'excluded_exceptions' => [],
        'error_tracking_context_provider' => null,
    ];

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

    public static function configure(Client $client, array $options): void
    {
        self::$client = $client;
        self::$options = self::normalizeOptions($options);

        ExceptionCapture::configure($options);

        if (!self::$options['enable_error_tracking']) {
            return;
        }

        if (
            self::$options['capture_uncaught_exceptions']
            && !self::$exceptionHandlerInstalled
        ) {
            self::$previousExceptionHandler = set_exception_handler([self::class, 'handleException']);
            self::$exceptionHandlerInstalled = true;
        }

        if (self::$options['capture_errors'] && !self::$errorHandlerInstalled) {
            self::$previousErrorHandler = set_error_handler(
                [self::class, 'handleError'],
                self::$options['error_reporting_mask']
            );
            self::$errorHandlerInstalled = true;
        }

        if (self::$options['capture_fatal_errors'] && !self::$shutdownHandlerRegistered) {
            register_shutdown_function([self::class, 'handleShutdown']);
            self::$shutdownHandlerRegistered = true;
        }
    }

    public static function handleException(\Throwable $exception): void
    {
        if (!self::shouldCaptureUncaughtExceptions()) {
            self::callPreviousExceptionHandler($exception);
            return;
        }

        if (!self::shouldCaptureThrowable($exception)) {
            self::callPreviousExceptionHandler($exception);
            return;
        }

        self::captureThrowable(
            $exception,
            'exception_handler',
            'php_exception_handler',
            ['type' => 'auto.exception_handler', 'handled' => false],
            null,
            null,
            null,
            null
        );

        self::flushSafely();
        self::callPreviousExceptionHandler($exception);
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

        if (in_array($errno, self::FATAL_ERROR_TYPES, true)) {
            // Fatal errors are handled from shutdown so we can flush at process end and avoid
            // double-sending the same failure from both the error and shutdown handlers.
            return self::delegateError($errno, $message, $file, $line);
        }

        $exception = new \ErrorException($message, 0, $errno, $file, $line);
        $exceptionList = ExceptionCapture::buildThrowableExceptionFromTrace(
            $exception,
            self::normalizeErrorHandlerTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))
        );

        if (self::shouldCaptureThrowable($exception)) {
            self::captureThrowable(
                $exception,
                'error_handler',
                'php_error_handler',
                ['type' => 'auto.error_handler', 'handled' => true],
                $errno,
                $message,
                $file,
                $line,
                $exceptionList
            );
        }

        return self::delegateError($errno, $message, $file, $line);
    }

    /**
     * @param array<string, mixed>|null $lastError
     */
    public static function handleShutdown(?array $lastError = null): void
    {
        if (!self::shouldCaptureFatalErrors()) {
            return;
        }

        $lastError = $lastError ?? error_get_last();
        if (!is_array($lastError)) {
            return;
        }

        $severity = $lastError['type'] ?? null;
        if (!is_int($severity) || !in_array($severity, self::FATAL_ERROR_TYPES, true)) {
            return;
        }

        $message = is_string($lastError['message'] ?? null) ? $lastError['message'] : '';
        $file = is_string($lastError['file'] ?? null) ? $lastError['file'] : '';
        $line = is_int($lastError['line'] ?? null) ? $lastError['line'] : 0;

        if (self::isDuplicateFatalError($severity, $message, $file, $line)) {
            return;
        }

        $exception = new \ErrorException($message, 0, $severity, $file, $line);
        // error_get_last() gives us location data but not a useful application backtrace. Build a
        // single location frame instead of using a fresh ErrorException trace, which would only
        // show handleShutdown().
        $exceptionList = ExceptionCapture::buildExceptionFromLocation(
            \ErrorException::class,
            $message,
            $file !== '' ? $file : null,
            $line !== 0 ? $line : null
        );

        if (!self::shouldCaptureThrowable($exception)) {
            return;
        }

        self::rememberFatalError($severity, $message, $file, $line);

        self::captureThrowable(
            $exception,
            'shutdown_handler',
            'php_shutdown_handler',
            ['type' => 'auto.shutdown_handler', 'handled' => false],
            $severity,
            $message,
            $file,
            $line,
            $exceptionList
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
    }

    private static function shouldCaptureUncaughtExceptions(): bool
    {
        return self::$options['enable_error_tracking']
            && self::$options['capture_uncaught_exceptions']
            && self::$client !== null;
    }

    private static function shouldCaptureErrors(): bool
    {
        return self::$options['enable_error_tracking']
            && self::$options['capture_errors']
            && self::$client !== null;
    }

    private static function shouldCaptureFatalErrors(): bool
    {
        return self::$options['enable_error_tracking']
            && self::$options['capture_fatal_errors']
            && self::$client !== null;
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

    private static function callPreviousExceptionHandler(\Throwable $exception): void
    {
        if (is_callable(self::$previousExceptionHandler)) {
            call_user_func(self::$previousExceptionHandler, $exception);
        }
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

    /**
     * @param array<string, mixed> $mechanism
     * @param array<string, mixed>|null $exceptionListOverride
     */
    private static function captureThrowable(
        \Throwable $exception,
        string $contextSource,
        string $eventSource,
        array $mechanism,
        ?int $severity,
        ?string $message,
        ?string $file,
        ?int $line,
        ?array $exceptionListOverride = null
    ): void {
        if (self::$client === null || self::$isCapturing) {
            return;
        }

        self::$isCapturing = true;

        try {
            $exceptionList = $exceptionListOverride ?? ExceptionCapture::buildParsedException($exception);
            if ($exceptionList === null) {
                return;
            }

            $exceptionList = ExceptionCapture::normalizeExceptionList($exceptionList);
            $exceptionList = ExceptionCapture::overridePrimaryMechanism($exceptionList, $mechanism);

            $providerContext = self::getProviderContext([
                'source' => $contextSource,
                'exception' => $exception,
                'severity' => $severity,
                'message' => $message ?? $exception->getMessage(),
                'file' => $file ?? $exception->getFile(),
                'line' => $line ?? $exception->getLine(),
            ]);

            $properties = [
                '$exception_list' => $exceptionList,
                '$exception_handled' => ExceptionCapture::getPrimaryHandled($exceptionList),
                '$exception_source' => $eventSource,
            ];

            if ($severity !== null) {
                $properties['$php_error_severity'] = $severity;
            }

            $properties = array_merge($providerContext['properties'], $properties);

            $distinctId = $providerContext['distinctId'];
            if ($distinctId === null) {
                $distinctId = self::generateUuidV4();
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
                // debug_backtrace() starts inside the active error handler. Drop registrar-owned
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
        $provider = self::$options['error_tracking_context_provider'];
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
        // Some runtimes surface the same fatal through multiple paths. Signature-based dedupe keeps
        // shutdown capture from sending duplicates for the same message/location pair.
        $signature = self::fatalErrorSignature($severity, $message, $file, $line);
        return isset(self::$fatalErrorSignatures[$signature]);
    }

    private static function rememberFatalError(
        int $severity,
        string $message,
        string $file,
        int $line
    ): void {
        $signature = self::fatalErrorSignature($severity, $message, $file, $line);
        self::$fatalErrorSignatures[$signature] = true;
    }

    private static function fatalErrorSignature(
        int $severity,
        string $message,
        string $file,
        int $line
    ): string {
        return implode('|', [$severity, $file, $line, $message]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeOptions(array $options): array
    {
        return [
            'enable_error_tracking' => (bool) ($options['enable_error_tracking'] ?? false),
            'capture_uncaught_exceptions' => (bool) ($options['capture_uncaught_exceptions'] ?? true),
            'capture_errors' => (bool) ($options['capture_errors'] ?? true),
            'capture_fatal_errors' => (bool) ($options['capture_fatal_errors'] ?? true),
            'error_reporting_mask' => (int) ($options['error_reporting_mask'] ?? E_ALL),
            'excluded_exceptions' => array_values(array_filter(
                is_array($options['excluded_exceptions'] ?? null) ? $options['excluded_exceptions'] : [],
                fn($class) => is_string($class) && $class !== ''
            )),
            'error_tracking_context_provider' => is_callable($options['error_tracking_context_provider'] ?? null)
                ? $options['error_tracking_context_provider']
                : null,
        ];
    }

    private static function generateUuidV4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
