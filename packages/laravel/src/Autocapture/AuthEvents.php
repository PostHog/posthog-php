<?php

namespace PostHog\Laravel\Autocapture;

use Illuminate\Container\Container;
use Throwable;

class AuthEvents
{
    public function collect(string $name, object $event): void
    {
        if (
            !Collector::enabled('auth') || !in_array($name, Container::getInstance()['config']->get(
                'posthog.autocapture.auth.events',
                [],
            ), true)
        ) {
            return;
        }
        try {
            $snapshot = Collector::snapshot();
            if (!in_array($name, ['failed', 'lockout'], true)) {
                $id = $event->user?->getAuthIdentifier();
                if (is_scalar($id) && (string) $id !== '') {
                    $snapshot['distinctId'] = (string) $id;
                }
            }
            $properties = isset($event->guard) && is_string($event->guard) ? ['guard' => $event->guard] : [];
            Collector::capture('laravel_auth_' . $name, $properties, $snapshot);
        } catch (Throwable) {
        }
    }
}
