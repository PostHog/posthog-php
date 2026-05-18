<?php

declare(strict_types=1);

namespace App\HttpKernel;

use PostHog\PostHog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Symfony HttpKernel decorator example for PostHog request context.
 *
 * Register this as a service decorating `http_kernel`. The `$useTracingHeaders`
 * constructor argument should come from your Symfony bundle/integration config.
 *
 * Example services.yaml:
 *
 * App\HttpKernel\PostHogRequestContextKernel:
 *   decorates: http_kernel
 *   arguments:
 *     $innerKernel: '@App\HttpKernel\PostHogRequestContextKernel.inner'
 *     $useTracingHeaders: '%posthog.use_tracing_headers%'
 */
final class PostHogRequestContextKernel implements HttpKernelInterface
{
    public function __construct(
        private HttpKernelInterface $innerKernel,
        private bool $useTracingHeaders = true
    ) {
    }

    public function handle(
        Request $request,
        int $type = HttpKernelInterface::MAIN_REQUEST,
        bool $catch = true
    ): Response {
        $context = $this->useTracingHeaders
            ? PostHog::contextFromHeaders($request->headers->all())
            : [];

        $context['properties'] = array_merge(
            $context['properties'] ?? [],
            array_filter(
                [
                    '$current_url' => $request->getUri(),
                    '$request_method' => $request->getMethod(),
                    '$request_path' => $request->getPathInfo(),
                    '$user_agent' => $request->headers->get('user-agent'),
                    '$ip' => $request->getClientIp(),
                ],
                static fn($value): bool => $value !== null && $value !== ''
            )
        );

        return PostHog::withContext(
            $context,
            fn(): Response => $this->innerKernel->handle($request, $type, $catch),
            ['fresh' => true]
        );
    }
}
