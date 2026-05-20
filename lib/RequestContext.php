<?php

namespace PostHog;

/**
 * Request-lifetime PostHog context used to attach frontend tracing headers and
 * request metadata to events captured during a PHP request/job scope.
 *
 * @internal Prefer the public PostHog/Client context APIs. This class remains
 * public only because PHP autoloading cannot hide implementation classes.
 */
final class RequestContext
{
    private const DEFAULT_CONTEXT_KEY = 0;
    private const MAX_HEADER_LENGTH = 1000;

    /**
     * Context is scoped first to a Client, then to the current Fiber when Fibers are used,
     * otherwise to the main execution path.
     *
     * @var array<int, array<int, array<int, array{scopeId: int, context: array<string, mixed>}>>>
     */
    private static array $stacks = [];

    private static int $nextScopeId = 1;

    /**
     * Run a callback with context that is restored automatically afterwards.
     *
     * @param array<string, mixed> $data
     * @param callable $fn
     * @param array<string, mixed> $options Use ['fresh' => true] to avoid inheriting the current context.
     * @param int|null $contextKey Internal Client scope key. Null keeps backwards-compatible global scope.
     * @return mixed
     * @throws \Throwable Re-throws any exception thrown by $fn after restoring context.
     */
    public static function withContext(
        array $data,
        callable $fn,
        array $options = [],
        ?int $contextKey = null
    ): mixed {
        $contextKey = self::contextKey($contextKey);
        $scopeId = self::push($data, (bool) ($options['fresh'] ?? false), $contextKey);

        try {
            return $fn();
        } finally {
            self::pop($scopeId, $contextKey);
        }
    }

    /**
     * Get the current context for a context scope.
     *
     * @param int|null $contextKey Internal Client scope key. Null keeps backwards-compatible global scope.
     * @return array{distinctId?: string|null, sessionId?: string|null, properties: array<string, mixed>}|null
     */
    public static function getContext(?int $contextKey = null): ?array
    {
        $contextKey = self::contextKey($contextKey);
        $fiberKey = self::fiberKey();

        if (empty(self::$stacks[$contextKey][$fiberKey])) {
            return null;
        }

        $stack = self::$stacks[$contextKey][$fiberKey];
        return $stack[array_key_last($stack)]['context'];
    }

    /**
     * Get the current distinct ID for a context scope.
     *
     * @param int|null $contextKey Internal Client scope key. Null keeps backwards-compatible global scope.
     * @return string|null
     */
    public static function getDistinctId(?int $contextKey = null): ?string
    {
        $context = self::getContext($contextKey);
        $distinctId = $context['distinctId'] ?? null;

        return $distinctId !== null && $distinctId !== '' ? (string) $distinctId : null;
    }

    /**
     * Clear all stored request contexts.
     *
     * @internal Test helper for clearing leaked context between tests/processes.
     * @return void
     */
    public static function reset(): void
    {
        self::$stacks = [];
        self::$nextScopeId = 1;
    }

    /**
     * Extract PostHog frontend tracing context from HTTP headers.
     *
     * Header names are matched case-insensitively and support $_SERVER-style HTTP_* keys.
     * This helper intentionally only reads X-PostHog-Distinct-Id and
     * X-PostHog-Session-Id. Framework integrations should attach request metadata
     * such as URL, IP address, and user agent from trusted
     * request APIs via the returned properties array.
     *
     * @param array<string, mixed> $headers
     * @return array{distinctId?: string|null, sessionId?: string|null, properties: array<string, mixed>}
     */
    public static function contextFromHeaders(array $headers): array
    {
        $distinctId = self::sanitizeHeaderValue(self::getHeader($headers, 'x-posthog-distinct-id'));
        $sessionId = self::sanitizeHeaderValue(self::getHeader($headers, 'x-posthog-session-id'));

        $properties = [];
        if ($sessionId !== null) {
            $properties['$session_id'] = $sessionId;
        }

        return [
            'distinctId' => $distinctId,
            'sessionId' => $sessionId,
            'properties' => $properties,
        ];
    }

    private static function sanitizeHeaderValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return substr($value, 0, self::MAX_HEADER_LENGTH);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function push(array $data, bool $fresh, int $contextKey): int
    {
        $current = $fresh ? ['properties' => []] : (self::getContext($contextKey) ?? ['properties' => []]);
        $scopeId = self::$nextScopeId++;
        $fiberKey = self::fiberKey();

        if (!array_key_exists($contextKey, self::$stacks)) {
            self::$stacks[$contextKey] = [];
        }
        if (!array_key_exists($fiberKey, self::$stacks[$contextKey])) {
            self::$stacks[$contextKey][$fiberKey] = [];
        }

        self::$stacks[$contextKey][$fiberKey][] = [
            'scopeId' => $scopeId,
            'context' => self::mergeContext($current, $data),
        ];

        return $scopeId;
    }

    private static function pop(int $scopeId, int $contextKey): void
    {
        $fiberKey = self::fiberKey();
        if (empty(self::$stacks[$contextKey][$fiberKey])) {
            return;
        }

        $lastKey = array_key_last(self::$stacks[$contextKey][$fiberKey]);
        if (self::$stacks[$contextKey][$fiberKey][$lastKey]['scopeId'] === $scopeId) {
            array_pop(self::$stacks[$contextKey][$fiberKey]);
            self::unsetStackIfEmpty($contextKey, $fiberKey);
            return;
        }

        foreach (self::$stacks[$contextKey][$fiberKey] as $index => $frame) {
            if ($frame['scopeId'] === $scopeId) {
                self::$stacks[$contextKey][$fiberKey] = array_slice(
                    self::$stacks[$contextKey][$fiberKey],
                    0,
                    $index
                );
                self::unsetStackIfEmpty($contextKey, $fiberKey);
                return;
            }
        }
    }

    private static function contextKey(?int $contextKey): int
    {
        return $contextKey ?? self::DEFAULT_CONTEXT_KEY;
    }

    private static function fiberKey(): int
    {
        $fiber = \Fiber::getCurrent();
        return $fiber === null ? 0 : spl_object_id($fiber);
    }

    private static function unsetStackIfEmpty(int $contextKey, int $fiberKey): void
    {
        if (empty(self::$stacks[$contextKey][$fiberKey])) {
            unset(self::$stacks[$contextKey][$fiberKey]);
        }

        if (empty(self::$stacks[$contextKey])) {
            unset(self::$stacks[$contextKey]);
        }
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $data
     * @return array{distinctId?: string|null, sessionId?: string|null, properties: array<string, mixed>}
     */
    private static function mergeContext(array $current, array $data): array
    {
        $data = self::normalizeContextData($data);

        return [
            'distinctId' => $data['distinctId'] ?? ($current['distinctId'] ?? null),
            'sessionId' => $data['sessionId'] ?? ($current['sessionId'] ?? null),
            'properties' => array_merge($current['properties'] ?? [], $data['properties'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{distinctId?: string|null, sessionId?: string|null, properties: array<string, mixed>}
     */
    private static function normalizeContextData(array $data): array
    {
        if (array_key_exists('distinct_id', $data) && !array_key_exists('distinctId', $data)) {
            $data['distinctId'] = $data['distinct_id'];
        }
        if (array_key_exists('session_id', $data) && !array_key_exists('sessionId', $data)) {
            $data['sessionId'] = $data['session_id'];
        }

        $context = ['properties' => []];

        if (
            array_key_exists('distinctId', $data)
            && is_scalar($data['distinctId'])
            && (string) $data['distinctId'] !== ''
        ) {
            $context['distinctId'] = (string) $data['distinctId'];
        }

        if (
            array_key_exists('sessionId', $data)
            && is_scalar($data['sessionId'])
            && (string) $data['sessionId'] !== ''
        ) {
            $context['sessionId'] = (string) $data['sessionId'];
            $context['properties']['$session_id'] = (string) $data['sessionId'];
        }

        if (isset($data['properties']) && is_array($data['properties'])) {
            $context['properties'] = array_merge($context['properties'], $data['properties']);
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private static function getHeader(array $headers, string $name): mixed
    {
        $normalizedName = strtolower(str_replace('_', '-', $name));
        foreach ($headers as $key => $value) {
            $headerName = strtolower(str_replace('_', '-', (string) $key));
            if (str_starts_with($headerName, 'http-')) {
                $headerName = substr($headerName, 5);
            }
            if ($headerName === $normalizedName) {
                return $value;
            }
        }

        return null;
    }
}
