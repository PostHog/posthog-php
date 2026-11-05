<?php

namespace PostHog\Laravel\Autocapture;

use Illuminate\Container\Container;
use PostHog\Laravel\Context;
use PostHog\Laravel\PostHog;
use Throwable;

/** @internal Collectors retain correlation IDs, groups and IP, never arbitrary context payloads. */
class Collector
{
    private array $loggedFailures = [];

    public static function enabled(?string $section = null): bool
    {
        $app = Container::getInstance();
        return ($section === null || $app['config']->get('posthog.autocapture.' . $section . '.enabled', false))
            && $app->make(PostHog::class)->enabled();
    }

    public static function snapshot(): array
    {
        $data = Container::getInstance()->make(Context::class)->snapshot();
        $snapshot = array_intersect_key($data, [
            'distinctId' => true, 'sessionId' => true, 'groups' => true,
        ]);
        if (array_key_exists('$ip', $data['properties'] ?? [])) {
            $snapshot['properties']['$ip'] = $data['properties']['$ip'];
        }
        return $snapshot;
    }

    public static function capture(string $event, array $properties, ?array $snapshot = null): void
    {
        $scope = null;
        try {
            $app = Container::getInstance();
            if (!self::enabled()) {
                return;
            }
            $snapshot ??= self::snapshot();
            $id = $snapshot['distinctId'] ?? null;
            $scope = $app->make(Context::class)->enterResolvedContext($snapshot);
            $app->make(PostHog::class)->capture([
                'event' => $event, 'properties' => $properties,
                'distinctId' => is_scalar($id) ? (string) $id : null, 'groups' => $snapshot['groups'] ?? [],
            ]);
        } catch (Throwable $exception) {
            self::logFailure(__METHOD__, $exception);
        } finally {
            $scope?->close();
        }
    }

    public static function logFailure(string $collector, Throwable $exception): void
    {
        try {
            $app = Container::getInstance();
            if (!$app['config']->get('posthog.sdk.debug', false)) {
                return;
            }
            $instance = $app->make(self::class);
            if (isset($instance->loggedFailures[$collector])) {
                return;
            }
            $instance->loggedFailures[$collector] = true;
            $app->make('log')->debug('[PostHog] Automatic event collection failed.', [
                'collector' => $collector, 'exception_class' => $exception::class,
            ]);
        } catch (Throwable) {
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
