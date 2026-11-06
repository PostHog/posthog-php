<?php

namespace PostHog\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Queue\InteractsWithQueue;
use PostHog\Laravel\Delivery\BatchDelivery;
use PostHog\Laravel\Delivery\DeliveryFailed;
use Throwable;

/**
 * @internal Stores only prepared event JSON, never SDK instances or credentials.
 * The class name and $events are persisted in queued and failed jobs. Keep both compatible with outstanding jobs.
 */
final class DeliverEvents implements ShouldQueue, ShouldBeEncrypted
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $events)
    {
    }

    public function handle(Container $app): void
    {
        if (!$app['config']->get('posthog.enabled', true) || $app['config']->get('posthog.sdk.consumer') === 'noop') {
            return;
        }
        try {
            $app->make(BatchDelivery::class)->deliver($this->events);
        } catch (DeliveryFailed $exception) {
            if (!$exception->retryable) {
                $this->fail($exception);
                return;
            }
            throw $exception;
        } catch (Throwable $exception) {
            throw new DeliveryFailed('PostHog queued delivery failed.', previous: $exception);
        }
    }
}
