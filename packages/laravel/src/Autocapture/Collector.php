<?php

namespace PostHog\Laravel\Autocapture;

use Illuminate\Container\Container;
use PostHog\Laravel\Context;
use PostHog\Laravel\PostHog;
use Throwable;

/** @internal Collectors retain only identity and groups, never arbitrary context payloads. */
class Collector
{
    public static function enabled(string $section): bool
    {
        return (bool) Container::getInstance()['config']->get('posthog.autocapture.' . $section . '.enabled', false);
    }

    public static function snapshot(): array
    {
        return array_intersect_key(Container::getInstance()->make(Context::class)->snapshot(), [
            'distinctId' => true, 'groups' => true,
        ]);
    }

    public static function capture(string $event, array $properties, ?array $snapshot = null): void
    {
        $context = null;
        try {
            $app = Container::getInstance();
            $snapshot ??= self::snapshot();
            $id = $snapshot['distinctId'] ?? null;
            if (!is_scalar($id) || (string) $id === '') {
                return;
            }
            $context = $app->make(Context::class);
            $context->enter();
            $context->begin($snapshot);
            $app->make(PostHog::class)->capture([
                'event' => $event, 'properties' => $properties,
                'distinctId' => (string) $id, 'groups' => $snapshot['groups'] ?? [],
            ]);
        } catch (Throwable) {
            // Analytics must never replace an application result or its original exception.
        } finally {
            $context?->leave();
        }
    }

    public static function jsonSafe(mixed $value, int $depth = 0): bool
    {
        if ($depth > 16 || is_object($value) || is_resource($value)) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::jsonSafe($item, $depth + 1)) {
                    return false;
                }
            }
        }
        return json_encode($value) !== false;
    }
}
