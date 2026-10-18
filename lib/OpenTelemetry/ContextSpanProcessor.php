<?php

namespace PostHog\OpenTelemetry;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use PostHog\Client;
use PostHog\Internal\SpanContext;

final class ContextSpanProcessor implements SpanProcessorInterface
{
    private bool $shutdown = false;

    public function __construct(private readonly Client $client)
    {
        SpanContext::register($client, $this);
    }

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        if (!$this->shutdown) {
            SpanContext::enrich($this->client, $span);
        }
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return !$this->shutdown;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        if ($this->shutdown) {
            return false;
        }

        $this->shutdown = true;
        SpanContext::unregister($this->client, $this);
        return true;
    }
}
