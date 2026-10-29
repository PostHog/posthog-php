<?php

namespace PostHog\Laravel\Autocapture;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;
use WeakReference;

/** @internal Weak keys prevent retaining jobs, command inputs or scheduled tasks in workers. */
class Outcomes
{
    private WeakMap $starts;
    private WeakMap $scheduled;
    private WeakMap $executed;

    public function __construct()
    {
        $this->starts = new WeakMap();
        $this->scheduled = new WeakMap();
        $this->executed = new WeakMap();
    }

    public function start(string $section, object $key): void
    {
        if (Collector::enabled($section)) {
            $this->starts[$key] = hrtime(true);
        }
    }

    public function startScheduler(Event $task): void
    {
        if (!Collector::enabled('scheduler')) {
            return;
        }
        $this->start('scheduler', $task);
        $this->executed[$task] = false;
        if (!isset($this->scheduled[$task])) {
            $this->scheduled[$task] = true;
            // The task owns this callback, so it must not capture the task strongly.
            $reference = WeakReference::create($task);
            $executed = $this->executed;
            $task->before(static function () use ($reference, $executed): void {
                if (($task = $reference->get()) !== null) {
                    $executed[$task] = true;
                }
            });
        }
    }

    public function finishScheduler(Event $task, bool $failed): void
    {
        $executed = $this->executed[$task] ?? false;
        unset($this->executed[$task]);
        $this->finish('scheduler', $task, static fn () => $failed || $executed ? [
            'name' => $task::class,
            'outcome' => $failed ? 'failed' : ($task->runInBackground ? 'background_started'
                : ($task->exitCode === 0 ? 'success' : 'failed')),
            'exit_code' => $task->exitCode,
        ] : []);
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
