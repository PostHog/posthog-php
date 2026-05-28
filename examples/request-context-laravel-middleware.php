<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PostHog\PostHog;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel HTTP middleware example for PostHog request context.
 *
 * Register this middleware in Laravel as usual.
 */
final class PostHogRequestContext
{
    public function __construct(private ?bool $useTracingHeaders = null)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $useTracingHeaders = $this->useTracingHeaders ?? (bool) config('posthog.use_tracing_headers', true);
        $context = $useTracingHeaders
            ? PostHog::contextFromHeaders($request->headers->all())
            : [];

        $context['properties'] = array_merge(
            $context['properties'] ?? [],
            array_filter(
                [
                    '$current_url' => $request->fullUrl(),
                    '$request_method' => $request->method(),
                    '$request_path' => $request->getPathInfo(),
                    '$user_agent' => $request->userAgent(),
                    '$ip' => $request->ip(),
                ],
                static fn($value): bool => $value !== null && $value !== ''
            )
        );

        return PostHog::withContext($context, static fn(): Response => $next($request), ['fresh' => true]);
    }
}
