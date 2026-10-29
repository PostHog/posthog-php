<?php

namespace PostHog\Laravel\Autocapture;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Throwable;

class RequestSummary
{
    public function handle(Request $request, Closure $next): mixed
    {
        $started = hrtime(true);
        $response = null;
        try {
            return $response = $next($request);
        } finally {
            try {
                $this->collect($request, $response, $started);
            } catch (Throwable) {
                // Never mask an application failure, including a failing collector callback.
            }
        }
    }

    private function collect(Request $request, mixed $response, int $started): void
    {
        if (!Collector::enabled('requests') && !Collector::enabled('pageviews')) {
            return;
        }
        $route = $request->route();
        if (!$route instanceof Route) {
            return;
        }
        $template = '/' . ltrim($route->uri(), '/');
        $config = Container::getInstance()['config'];
        foreach ($config->get('posthog.autocapture.requests.exclude', []) as $pattern) {
            $pattern = ltrim($pattern, '/');
            if (Str::is($pattern, ltrim($template, '/')) || Str::is($pattern, $request->path())) {
                return;
            }
        }
        $rate = max(0.0, min(1.0, (float) $config->get('posthog.autocapture.requests.sample_rate', 1.0)));
        if ($rate === 0.0 || ($rate < 1.0 && random_int(1, 1000000) / 1000000 > $rate)) {
            return;
        }
        $properties = [
            'route' => $template,
            'method' => $request->method(),
            'status_code' => $response?->getStatusCode() ?? 500,
            'duration_ms' => (hrtime(true) - $started) / 1e6,
        ];
        if (Collector::enabled('requests')) {
            Collector::capture('laravel_request', $properties);
        }
        if (
            Collector::enabled('pageviews') && $request->isMethod('GET')
            && $properties['status_code'] >= 200 && $properties['status_code'] < 300
            && str_starts_with(strtolower($response?->headers->get('Content-Type', '') ?? ''), 'text/html')
        ) {
            // A configured origin avoids trusting a request Host header or exposing URL credentials.
            $url = parse_url((string) $config->get('app.url'));
            if (
                !is_array($url) || !isset($url['scheme'], $url['host'])
                || !in_array($url['scheme'], ['http', 'https'], true)
            ) {
                return;
            }
            $origin = $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
            Collector::capture('$pageview', $properties + [
                '$current_url' => $origin . $template, '$pathname' => $template,
            ]);
        }
    }
}
