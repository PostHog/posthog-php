<?php

namespace PostHog\Laravel\Autocapture;

use Illuminate\Container\Container;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/** @internal Weak keys prevent retaining jobs, command inputs or scheduled tasks in workers. */
class Outcomes
{
    private WeakMap $starts;

    public function __construct()
    {
        $this->starts = new WeakMap();
    }

    public function start(string $section, object $key): void
    {
        if (Collector::enabled($section)) {
            $this->starts[$key] = hrtime(true);
        }
    }

    public function finish(string $section, object $key, callable $metadata): void
    {
        if (!isset($this->starts[$key])) {
            return;
        }
        $started = $this->starts[$key];
        unset($this->starts[$key]);
        try {
            if (!Collector::enabled($section)) {
                return;
            }
            $properties = $metadata();
            if (!is_string($properties['name'] ?? null) || $properties['name'] === '') {
                return;
            }
            $excluded = Container::getInstance()['config']->get('posthog.autocapture.' . $section . '.exclude', []);
            foreach ($excluded as $pattern) {
                if (Str::is($pattern, $properties['name'])) {
                    return;
                }
            }
            $properties['duration_ms'] = (hrtime(true) - $started) / 1e6;
            $event = [
                'jobs' => 'laravel_job', 'commands' => 'laravel_command', 'scheduler' => 'laravel_scheduled_task',
            ][$section];
            Collector::capture($event, $properties);
        } catch (Throwable) {
        }
    }
}
