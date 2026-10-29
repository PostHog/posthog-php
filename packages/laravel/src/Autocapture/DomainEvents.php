<?php

namespace PostHog\Laravel\Autocapture;

use Throwable;

class DomainEvents
{
    public function collect(object $event, array $mapping): void
    {
        try {
            if (!isset($mapping['event']) || !is_string($mapping['event']) || $mapping['event'] === '') {
                return;
            }
            $properties = [];
            foreach ($mapping['properties'] ?? [] as $name => $path) {
                if (!is_string($name) || !is_string($path)) {
                    continue;
                }
                $value = $event;
                foreach (explode('.', $path) as $part) {
                    // Public properties and array keys only: never invoke model magic or getters.
                    $values = is_object($value) ? get_object_vars($value) : $value;
                    if (!is_array($values) || !array_key_exists($part, $values)) {
                        continue 2;
                    }
                    $value = $values[$part];
                }
                if (Collector::jsonSafe($value)) {
                    $properties[$name] = $value;
                }
            }
            Collector::capture($mapping['event'], $properties);
        } catch (Throwable) {
        }
    }
}
