<?php

namespace PostHog\Laravel\Middleware;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use PostHog\Laravel\ExceptionReporter;
use PostHog\Laravel\RequestLifecycle;
use Throwable;

/** @internal */
final class RequestContext
{
    public function __construct(
        private RequestLifecycle $lifecycle,
        private ExceptionReporter $reporter,
        private Kernel $kernel,
    ) {
    }

    public function handle($request, Closure $next): mixed
    {
        $this->lifecycle->begin($request);
        try {
            return $next($request);
        } catch (Throwable $exception) {
            $this->reporter->remember($exception, 'middleware', 'laravel.middleware');
            throw $exception;
        } finally {
            if (!method_exists($this->kernel, 'whenRequestLifecycleIsLongerThan')) {
                $this->lifecycle->finish();
            }
        }
    }
}
