<?php

namespace PostHog\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PostHog\FeatureFlagEvaluations;
use PostHog\PostHog as CorePostHog;
use Throwable;

class Context
{
    private array $context = [];
    private array $flags = [];
    private array $scopes = [];
    private ?Request $request = null;

    public function __construct(private Container $app)
    {
    }

    public function begin(array $data = []): void
    {
        $this->context = $data;
        $this->flags = [];
        $this->request = null;
    }

    public function end(): void
    {
        $this->begin();
    }

    public function data(): array
    {
        $data = $this->context;
        if ($this->request !== null) {
            $data['properties']['$request_method'] ??= $this->request->method();
            $route = $this->request->route();
            if ($route instanceof Route) {
                $data['properties']['$request_path'] ??= '/' . ltrim($route->uri(), '/');
            }
        }
        try {
            $resolver = $this->app['config']->get('posthog.distinct_id');
            $id = $resolver ? ($this->app->make($resolver))($this->app) : null;
            if ($id === null && $this->request !== null) {
                $id = $this->request->user()?->getAuthIdentifier();
            }
        } catch (Throwable) {
            return $data;
        }
        if (is_scalar($id) && (string) $id !== '') {
            $data['distinctId'] = (string) $id;
        }
        $groupsResolver = $this->app['config']->get('posthog.groups');
        if ($groupsResolver !== null) {
            try {
                $groups = ($this->app->make($groupsResolver))($this->app);
                if (is_array($groups)) {
                    $data['groups'] = array_replace($groups, $data['groups'] ?? []);
                }
            } catch (Throwable) {
            }
        }
        return $data;
    }

    public function snapshot(): array
    {
        return $this->data();
    }

    public function with(array $data, callable $callback): mixed
    {
        $previous = $this->context;
        $flags = $this->flags;
        $request = $this->request;
        $this->begin(array_replace_recursive($previous, $data));
        $this->request = $request;
        try {
            return $callback();
        } catch (Throwable $exception) {
            $this->app->make(ExceptionReporter::class)->rememberContext($exception);
            throw $exception;
        } finally {
            $this->context = $previous;
            $this->flags = $flags;
            $this->request = $request;
        }
    }

    public function fromRequest(Request $request): void
    {
        $this->begin($this->app['config']->get('posthog.header_context', true)
            ? CorePostHog::contextFromHeaders($request->headers->all()) : []);
        $this->request = $request;
    }

    /** @internal Isolate nested queue executions without discarding the caller's context. */
    public function enter(): void
    {
        $this->scopes[] = [$this->context, $this->flags, $this->request];
        $this->begin();
    }

    /** @internal Restore the scope active before queue execution. */
    public function leave(): void
    {
        if ($this->scopes === []) {
            $this->end();
            return;
        }
        [$this->context, $this->flags, $this->request] = array_pop($this->scopes);
    }

    public function flags(string $key, callable $evaluate): FeatureFlagEvaluations
    {
        return $this->flags[$key] ??= $evaluate();
    }
}
