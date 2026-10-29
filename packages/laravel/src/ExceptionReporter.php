<?php

namespace PostHog\Laravel;

use Illuminate\Contracts\Container\Container;
use Throwable;
use WeakMap;

class ExceptionReporter
{
    private WeakMap $snapshots;
    private WeakMap $reported;

    public function __construct(private Container $app)
    {
        $this->snapshots = new WeakMap();
        $this->reported = new WeakMap();
    }

    public function rememberContext(Throwable $exception): void
    {
        $this->snapshots[$exception] ??= ['data' => $this->app->make(Context::class)->snapshot()];
    }

    public function remember(Throwable $exception, string $mechanism): void
    {
        $this->rememberContext($exception);
        $snapshot = $this->snapshots[$exception];
        $snapshot['options'] ??= ['handled' => false, 'mechanism' => $mechanism];
        $this->snapshots[$exception] = $snapshot;
    }

    private function reportOptions(): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';
            $method = $frame['function'] ?? '';
            // Laravel's routing pipeline consumes failures before outer middleware can catch them.
            if (
                ($method === 'handleException' && is_a($class, \Illuminate\Routing\Pipeline::class, true))
                || ($method === 'reportException' && is_a($class, \Illuminate\Foundation\Http\Kernel::class, true))
            ) {
                return ['handled' => false, 'mechanism' => 'middleware'];
            }
            if (
                ($method === 'reportException' && is_a($class, \Illuminate\Foundation\Console\Kernel::class, true))
                || ($method === 'handle' && is_a($class, 'Laravel\\Octane\\Listeners\\ReportException', true))
            ) {
                return ['handled' => false, 'mechanism' => 'runtime'];
            }
        }
        return ['handled' => true, 'mechanism' => 'middleware'];
    }

    public function report(Throwable $exception): void
    {
        if (isset($this->reported[$exception]) || !$this->app['config']->get('posthog.capture_exceptions', true)) {
            return;
        }
        $this->reported[$exception] = true;
        $snapshot = $this->snapshots[$exception] ?? ['data' => $this->app->make(Context::class)->snapshot()];
        $options = $snapshot['options'] ?? $this->reportOptions();
        $manager = $this->app->make(PostHog::class);
        try {
            $manager->reportException($exception, $snapshot['data'], $options);
            $manager->flush();
        } catch (Throwable) {
            // Reporting must never replace the application's original failure.
        }
    }
}
