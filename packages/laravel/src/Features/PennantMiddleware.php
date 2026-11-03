<?php

namespace PostHog\Laravel\Features;

use Closure;

/** @internal */
final class PennantMiddleware
{
    public function handle($request, Closure $next): mixed
    {
        PennantCache::flush();
        try {
            return $next($request);
        } finally {
            PennantCache::flush();
        }
    }
}
