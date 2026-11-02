<?php

namespace PostHog\Laravel;

use Illuminate\Container\Container;
use Illuminate\Queue\Queue;
use PostHog\PostHog as CorePostHog;

/** @internal Queue metadata is analytics context, never authorization evidence. */
final class QueueContext
{
    public static function register(): void
    {
        Queue::createPayloadUsing([self::class, 'payload']);
    }

    public static function payload(): array
    {
        $app = Container::getInstance();
        if (!$app['config']->get('posthog.queue_context', true) || !$app->make(PostHog::class)->enabled()) {
            return [];
        }
        $data = self::normalize($app->make(Context::class)->data());
        return $data === [] ? [] : ['_posthog' => $data];
    }

    public static function normalize(array $data): array
    {
        $headers = [];
        foreach (['distinctId' => 'X-PostHog-Distinct-Id', 'sessionId' => 'X-PostHog-Session-Id'] as $key => $header) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $headers[$header] = (string) $data[$key];
            }
        }
        $context = CorePostHog::contextFromHeaders($headers);
        $context = array_filter($context, static fn ($value) => $value !== null && $value !== []);
        if (isset($data['groups']) && is_array($data['groups'])) {
            foreach (array_slice($data['groups'], 0, 32, true) as $type => $value) {
                if (is_string($type) && strlen($type) <= 100 && is_scalar($value) && strlen((string) $value) <= 1000) {
                    $context['groups'][$type] = (string) $value;
                }
            }
        }
        return $context;
    }
}
