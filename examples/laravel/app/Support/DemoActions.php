<?php

namespace App\Support;

use App\Events\OrderCompleted;
use App\Jobs\DemoJob;
use App\Models\DemoOrder;
use Illuminate\Support\Facades\DB;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use RuntimeException;

final class DemoActions
{
    public function ambient(callable $callback): mixed
    {
        $context = app(Context::class);
        return $context->with([
            'distinctId' => $context->data()['distinctId'] ?? 'demo-visitor',
            'groups' => ['team' => 'demo-team'],
            'properties' => ['component' => 'laravel-demo'],
        ], $callback);
    }

    public function capture(): array
    {
        return $this->ambient(function (): array {
            $explicit = PostHog::capture('demo_explicit', ['source' => 'form'], 'demo-explicit', ['team' => 'demo-team']);
            $ambient = PostHog::capture('demo_ambient', ['source' => 'context']);
            return ['explicit_accepted' => $explicit, 'ambient_accepted' => $ambient];
        });
    }

    public function identity(): array
    {
        return [
            'identified' => PostHog::identify(['distinctId' => 'demo-member', 'properties' => ['plan' => 'demo']]),
            'aliased' => PostHog::alias(['distinctId' => 'demo-member', 'alias' => 'demo-visitor']),
            'group_identified' => PostHog::groupIdentify([
                'groupType' => 'team', 'groupKey' => 'demo-team', 'properties' => ['plan' => 'demo'],
            ]),
        ];
    }

    public function flags(): array
    {
        return $this->ambient(function (): array {
            $flags = PostHog::evaluateFlags(
                personProperties: ['plan' => 'demo'],
                groupProperties: ['team' => ['plan' => 'demo']],
                onlyEvaluateLocally: (bool) config('demo.local_only'),
            );
            $enabled = $flags->isEnabled('demo-checkout');
            $variant = $flags->getFlag('demo-layout');
            $payload = $flags->getFlagPayload('demo-layout'); // Silent: no exposure event.
            PostHog::capture([
                'event' => 'demo_flag_decision',
                'properties' => ['checkout_enabled' => $enabled],
                'flags' => $flags->onlyAccessed(),
            ]);
            return [
                'enabled' => $enabled,
                'variant' => $variant,
                'payload' => $payload,
                'keys' => $flags->getKeys(),
                // An unresolved flag is not the same as a disabled flag or a missing definition.
                'unresolved' => $flags->getUnresolvedFlags(),
                'attached' => $flags->onlyAccessed()->getEventProperties(),
            ];
        });
    }

    public function pennant(): array
    {
        return $this->ambient(function (): array {
            $store = \Laravel\Pennant\Feature::store('posthog');
            return [
                'enabled' => $store->active('demo-checkout'),
                'variant' => $store->value('demo-layout'),
                'read_only' => true,
            ];
        });
    }

    public function exception(bool $report = false): array
    {
        return $this->ambient(function () use ($report): array {
            $exception = new RuntimeException('Synthetic Laravel demo failure');
            if ($report) {
                report($exception); // Handled report; Laravel's reportable pipeline owns this capture.
                return ['reported' => true];
            }
            return ['captured' => PostHog::captureException(
                $exception,
                properties: ['component' => 'manual-demo'],
                options: ['handled' => true, 'mechanism' => 'generic'],
            )];
        });
    }

    public function order(): array
    {
        return $this->ambient(function (): array {
            $order = DB::transaction(function (): DemoOrder {
                $order = DemoOrder::create(['amount' => 4200, 'status' => 'completed']);
                // Explicit domain mapping collects amount only; no model/event serialization.
                event(new OrderCompleted($order->amount));
                return $order;
            });
            return ['order_id' => $order->id, 'amount' => $order->amount];
        });
    }

    public function queue(): array
    {
        return $this->ambient(function (): array {
            // Runtime propagates only bounded identity/session/scalar groups, never arbitrary properties.
            DemoJob::dispatch('demo-job-owner');
            return ['dispatched' => true, 'connection' => config('queue.default')];
        });
    }
}
