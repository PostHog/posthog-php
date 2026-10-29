<?php

namespace PostHog\Laravel\Middleware;

use Closure;
use PostHog\Laravel\Context;
use PostHog\Laravel\ExceptionReporter;
use PostHog\Laravel\PostHog;
use Throwable;

class RequestContext
{
    public function __construct(private Context $context, private ExceptionReporter $reporter, private PostHog $posthog)
    {
    }

    public function handle($request, Closure $next): mixed
    {
        $this->context->fromRequest($request);
        try {
            return $next($request);
        } catch (Throwable $exception) {
            $this->reporter->remember($exception, 'middleware');
            throw $exception;
        } finally {
            try {
                $this->posthog->flush();
            } catch (Throwable) {
                // A transport failure must not change the HTTP response.
            } finally {
                $this->context->end();
            }
        }
    }
}
