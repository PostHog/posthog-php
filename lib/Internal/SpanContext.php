<?php

namespace PostHog\Internal;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use PostHog\Client;

/** @internal */
final class SpanContext
{
    private static ?\WeakMap $processors = null;

    public static function register(Client $client, object $processor): void
    {
        self::$processors ??= new \WeakMap();
        self::$processors[$client] ??= new \WeakMap();
        self::$processors[$client][$processor] = true;
    }

    public static function unregister(Client $client, object $processor): void
    {
        if (isset(self::$processors[$client])) {
            unset(self::$processors[$client][$processor]);
        }
    }

    public static function enrichCurrent(Client $client): void
    {
        // Uninitialized OpenTelemetry Fiber storage can return another Fiber's span.
        // Its public API has no safe initialization check.
        if (\Fiber::getCurrent() !== null || count(self::$processors[$client] ?? []) === 0) {
            return;
        }

        try {
            if (class_exists(Span::class)) {
                self::enrich($client, Span::getCurrent());
            }
        } catch (\Throwable) {
        }
    }

    public static function enrich(Client $client, SpanInterface $span): void
    {
        try {
            if (!$span instanceof ReadWriteSpanInterface || !$span->isRecording()) {
                return;
            }

            $context = $client->getContext();
            if ($context === null || !self::isEnabled($client)) {
                return;
            }

            $attributes = $span->toSpanData()->getAttributes();
            foreach (['posthogDistinctId' => 'distinctId', 'sessionId' => 'sessionId'] as $attribute => $key) {
                $value = $context[$key] ?? null;
                if (is_string($value) && $value !== '' && !$attributes->has($attribute)) {
                    $span->setAttribute($attribute, $value);
                }
            }
        } catch (\Throwable) {
        }
    }

    private static function isEnabled(Client $client): bool
    {
        return \Closure::bind(fn (): bool => $this->enabled === true, $client, Client::class)();
    }
}
