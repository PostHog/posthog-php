<?php

namespace PostHog\Laravel\Middleware;

use Closure;
use PostHog\Laravel\Context;
use PostHog\Laravel\PostHog;
use Throwable;

/** @internal */
final class AnonymousIdentity
{
    public function __construct(private Context $context, private PostHog $posthog)
    {
    }

    public function handle($request, Closure $next): mixed
    {
        if (!config('posthog.anonymous_id', false) || !$this->posthog->enabled()) {
            return $next($request);
        }
        try {
            return $next($request);
        } finally {
            try {
                // Resolve before StartSession saves, even if only an outer collector needs identity.
                $this->context->data();
            } catch (Throwable) {
                // Analytics must not replace the response or an application exception.
            }
        }
    }
}
