<?php

namespace PostHog\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PostHog\ContextScope;
use PostHog\FeatureFlagEvaluations;
use PostHog\PostHog as CorePostHog;
use Throwable;

class Context
{
    private array $context = [];
    private array $flags = [];
    private array $scopes = [];
    private ?Request $request = null;
    private bool $headerIdentity = false;
    private bool $resolved = false;
    private array $contextScopes = [];
    private int $nextScopeId = 0;
    private array $loggedResolverFailures = [];

    public function __construct(private Container $app)
    {
    }

    public function begin(array $data = []): void
    {
        $this->context = $data;
        $this->flags = [];
        $this->request = null;
        $this->headerIdentity = false;
        $this->resolved = false;
        $this->loggedResolverFailures = [];
    }

    public function end(): void
    {
        $this->begin();
    }

    public function data(): array
    {
        $data = $this->context;
        if ($this->resolved) {
            return $data;
        }
        if ($this->request !== null) {
            $data['properties']['$request_method'] ??= $this->request->method();
            $route = $this->request->route();
            if ($route instanceof Route) {
                $data['properties']['$request_path'] ??= '/' . ltrim($route->uri(), '/');
            }
            $config = $this->app['config'];
            if (
                $config->get('posthog.capture_ip', true) && $this->app->make(PostHog::class)->enabled()
                && !array_key_exists('$ip', $data['properties'] ?? [])
            ) {
                try {
                    $ip = $this->request->ip();
                    if (is_string($ip) && $ip !== '') {
                        $data['properties']['$ip'] = $ip;
                    }
                } catch (Throwable $exception) {
                    $this->logResolverFailure('request IP', $exception);
                }
            }
        }
        $resolver = $this->app['config']->get('posthog.distinct_id');
        $explicitId = !$this->headerIdentity && isset($data['distinctId']);
        $id = $explicitId ? $data['distinctId'] : null;
        if (!$explicitId && $resolver) {
            try {
                $id = ($this->app->make($resolver))($this->app);
            } catch (Throwable $exception) {
                $this->logResolverFailure('distinct_id', $exception);
            }
        }
        if ($id === null && $this->request !== null) {
            try {
                $id = $this->request->user()?->getAuthIdentifier();
            } catch (Throwable $exception) {
                $this->logResolverFailure('authenticated user', $exception);
            }
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
            } catch (Throwable $exception) {
                $this->logResolverFailure('groups', $exception);
            }
        }
        return $data;
    }

    private function logResolverFailure(string $resolver, Throwable $exception): void
    {
        if (!$this->app['config']->get('posthog.sdk.debug', false) || isset($this->loggedResolverFailures[$resolver])) {
            return;
        }
        $this->loggedResolverFailures[$resolver] = true;
        try {
            $this->app->make('log')->debug('[PostHog] Failed to resolve ' . $resolver . ' context.', [
                'exception_class' => $exception::class,
            ]);
        } catch (Throwable) {
            // A failing application logger must not interrupt analytics context fallback.
        }
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
        $headerIdentity = $this->headerIdentity;
        $resolved = $this->resolved;
        $loggedResolverFailures = $this->loggedResolverFailures;
        foreach (['distinct_id' => 'distinctId', 'session_id' => 'sessionId'] as $alias => $canonical) {
            if (array_key_exists($alias, $data) && !array_key_exists($canonical, $data)) {
                $data[$canonical] = $data[$alias];
            }
            unset($data[$alias]);
        }
        $sessionId = $data['sessionId'] ?? null;
        if (
            is_scalar($sessionId) && (string) $sessionId !== ''
            && !array_key_exists('$session_id', $data['properties'] ?? [])
        ) {
            $data['properties']['$session_id'] = (string) $sessionId;
        }
        $this->begin(array_replace_recursive($previous, $data));
        $this->resolved = $resolved;
        $this->request = $request;
        $this->headerIdentity = !array_key_exists('distinctId', $data) && $headerIdentity;
        try {
            return $callback();
        } catch (Throwable $exception) {
            $this->app->make(ExceptionReporter::class)->rememberContext($exception);
            throw $exception;
        } finally {
            $this->context = $previous;
            $this->flags = $flags;
            $this->request = $request;
            $this->headerIdentity = $headerIdentity;
            $this->resolved = $resolved;
            $this->loggedResolverFailures = $loggedResolverFailures;
        }
    }

    /** @internal Install resolved manager context without retaining a request or re-running resolvers. */
    public function enterResolvedContext(array $data): ContextScope
    {
        $id = $this->nextScopeId++;
        $this->contextScopes[$id] = $this->state();
        $this->begin($data);
        $this->resolved = true;
        return new ContextScope(function () use ($id): void {
            $ids = array_keys($this->contextScopes);
            $index = array_search($id, $ids, true);
            if ($index === false) {
                return;
            }
            if (isset($ids[$index + 1])) {
                $this->contextScopes[$ids[$index + 1]] = $this->contextScopes[$id];
            } else {
                $this->restore($this->contextScopes[$id]);
            }
            unset($this->contextScopes[$id]);
        });
    }

    private function state(): array
    {
        return [
            $this->context, $this->flags, $this->request, $this->headerIdentity,
            $this->resolved, $this->loggedResolverFailures,
        ];
    }

    private function restore(array $state): void
    {
        [
            $this->context, $this->flags, $this->request, $this->headerIdentity,
            $this->resolved, $this->loggedResolverFailures,
        ] = $state;
    }

    /** @internal */
    public function fromRequest(Request $request): void
    {
        $this->begin($this->app['config']->get('posthog.header_context', true)
            ? CorePostHog::contextFromHeaders($request->headers->all()) : []);
        $this->request = $request;
        $this->headerIdentity = true;
    }

    /** @internal Isolate nested queue executions without discarding the caller's context. */
    public function enter(): void
    {
        $this->scopes[] = $this->state();
        $this->begin();
    }

    /** @internal Restore the scope active before queue execution. */
    public function leave(): void
    {
        if ($this->scopes === []) {
            $this->end();
            return;
        }
        $this->restore(array_pop($this->scopes));
    }

    /** @internal */
    public function flags(string $key, callable $evaluate): FeatureFlagEvaluations
    {
        return $this->flags[$key] ??= $evaluate();
    }
}
