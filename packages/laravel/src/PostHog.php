<?php

namespace PostHog\Laravel;

use Closure;
use Illuminate\Contracts\Container\Container;
use PostHog\Client;
use PostHog\ContextScope;
use PostHog\FeatureFlagEvaluations;
use PostHog\FeatureFlagResult;
use Throwable;

class PostHog
{
    public const VERSION = '0.1.0';

    private ?Client $client = null;
    private ?int $definitionsLoadedAt = null;

    public function __construct(protected Container $app)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->app['config']->get('posthog.enabled', true)
            && trim((string) $this->app['config']->get('posthog.api_key')) !== '';
    }

    public function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        if (!$this->enabled()) {
            return $this->client = new Client(null, ['consumer' => 'noop'], loadFeatureFlags: false);
        }
        $config = $this->app['config']->get('posthog', []);
        $options = $config['sdk'] ?? [];
        $options += ['disableGeoip' => true];
        $options['host'] = $config['host'] ?? 'https://us.i.posthog.com';
        // Laravel owns reporting and process-wide PHP handlers.
        $options['error_tracking'] = array_replace($options['error_tracking'] ?? [], [
            'enabled' => false,
        ]);
        foreach (['before_send', 'error_handler', 'flag_definition_cache_provider'] as $key) {
            if (isset($options[$key]) && is_string($options[$key])) {
                $options[$key] = $this->app->make($options[$key]);
            }
        }
        if (isset($options['error_handler'])) {
            $options['error_handler'] = Closure::fromCallable($options['error_handler']);
        }
        if (!isset($options['flag_definition_cache_provider']) && ($config['flag_cache']['enabled'] ?? false)) {
            $options['flag_definition_cache_provider'] = $this->app->make(FlagDefinitionCacheProvider::class);
        }
        return $this->client = new LaravelClient(
            $config['api_key'],
            $options,
            $config['release_id'] ?? null,
            $config['secret_key'] ?? null,
        );
    }

    public function capture(
        string|array $event,
        array $properties = [],
        ?string $distinctId = null,
        array $groups = [],
    ): bool {
        $message = is_array($event) ? $event : ['event' => $event, 'properties' => $properties];
        if ($distinctId !== null) {
            $message['distinctId'] = $distinctId;
        }
        if ($groups !== []) {
            $message['groups'] = $groups;
        }
        return $this->message('capture', $message);
    }

    public function identify(array $message): bool
    {
        return $this->message('identify', $message);
    }

    public function alias(array $message): bool
    {
        return $this->message('alias', $message);
    }

    public function groupIdentify(array $message): bool
    {
        return $this->message('groupIdentify', $message);
    }

    protected function message(string $method, array $message): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $data = $this->app->make(Context::class)->data();
        if (isset($data['groups']) && !array_key_exists('groups', $message)) {
            $message['groups'] = $data['groups'];
        }
        return $this->client()->withContext(
            $data,
            fn () => (bool) $this->client()->$method($message),
            ['fresh' => true],
        );
    }

    public function captureException(
        Throwable|string $exception,
        ?string $distinctId = null,
        array $properties = [],
        array $options = [],
    ): bool {
        if (!$this->enabled()) {
            return false;
        }
        $data = $this->app->make(Context::class)->data();
        $properties += isset($data['groups']) ? ['$groups' => $data['groups']] : [];
        return $this->client()->withContext(
            $data,
            fn () => $this->client()->captureException($exception, $distinctId, $properties, $options),
            ['fresh' => true],
        );
    }

    public function evaluateFlags(
        ?string $distinctId = null,
        array $groups = [],
        array $personProperties = [],
        array $groupProperties = [],
        bool $onlyEvaluateLocally = false,
        ?bool $disableGeoip = null,
        ?array $flagKeys = null,
    ): FeatureFlagEvaluations {
        $context = $this->app->make(Context::class);
        $data = $context->data();
        $distinctId ??= $data['distinctId'] ?? null;
        $groups = $groups ?: ($data['groups'] ?? []);
        $disableGeoip ??= $this->app['config']->get('posthog.sdk.disableGeoip', true);
        $inputs = [
            $distinctId, $groups, $personProperties, $groupProperties, $onlyEvaluateLocally, $disableGeoip, $flagKeys,
        ];
        if (!$this->enabled() || $distinctId === null || $distinctId === '') {
            return new FeatureFlagEvaluations(
                $distinctId ?? '',
                [],
                $groups,
                $this->client(),
                disableGeoip: $disableGeoip,
            );
        }
        return $context->flags(hash('sha256', serialize($inputs)), function () use ($inputs, $flagKeys, $data) {
            if ($flagKeys !== []) {
                $this->loadDefinitionsIfNeeded();
            }
            return $this->client()->withContext(
                $data,
                fn () => $this->client()->evaluateFlags(...$inputs),
                ['fresh' => true],
            );
        });
    }

    private function loadDefinitionsIfNeeded(): void
    {
        $config = $this->app['config']->get('posthog');
        $hasDefinitions = !empty($config['secret_key'])
            || !empty($config['sdk']['flag_definition_cache_provider'])
            || ($config['flag_cache']['enabled'] ?? false);
        $interval = max(1, (int) ($config['flag_cache']['refresh_interval'] ?? 30));
        $refreshDue = $this->definitionsLoadedAt === null || time() - $this->definitionsLoadedAt >= $interval;
        if ($hasDefinitions && $refreshDue) {
            $this->client()->loadFlags();
            $this->definitionsLoadedAt = time();
        }
    }

    public function flush(): bool
    {
        return $this->client !== null ? (bool) $this->client->flush() : false;
    }

    public function shutdown(): bool
    {
        return $this->client !== null ? $this->client->shutdown() : false;
    }

    public function withContext(array $data, callable $fn, array $options = []): mixed
    {
        $scope = $this->enterContext($data, $options);
        try {
            return $fn();
        } catch (Throwable $exception) {
            $this->app->make(ExceptionReporter::class)->rememberContext($exception);
            throw $exception;
        } finally {
            $scope->close();
        }
    }

    public function enterContext(array $data, array $options = []): ContextScope
    {
        $context = $this->app->make(Context::class);
        $client = $this->client();
        $scopes = [];
        $groups = [];
        if (!($options['fresh'] ?? false)) {
            $parent = $context->data();
            $groups = $parent['groups'] ?? [];
            $raw = $client->getContext();
            $scopes[] = $client->enterContext($parent, ['fresh' => true]);
            if ($raw !== null) {
                $scopes[] = $client->enterContext($raw);
            }
        }
        $scopes[] = $client->enterContext($data, $options);
        $resolved = $client->getContext();
        if (array_key_exists('groups', $data)) {
            $groups = $data['groups'];
        }
        if ($groups !== []) {
            $resolved['groups'] = $groups;
        }
        $scope = $context->enterResolvedContext($resolved);
        return new ContextScope(static function () use ($scope, $scopes): void {
            $scope->close();
            foreach (array_reverse($scopes) as $coreScope) {
                $coreScope->close();
            }
        });
    }

    public function getContext(): ?array
    {
        return $this->withContext([], fn () => $this->client()->getContext());
    }

    /** @deprecated Use evaluateFlags()->isEnabled() instead. */
    public function isFeatureEnabled(
        string $key,
        ?string $distinctId = null,
        array $groups = [],
        array $personProperties = [],
        array $groupProperties = [],
        bool $onlyEvaluateLocally = false,
        bool $sendFeatureFlagEvents = true,
    ): ?bool {
        return $this->callFlagMethod(__FUNCTION__, func_get_args(), $distinctId);
    }

    /** @deprecated Use evaluateFlags()->getFlag() instead. */
    public function getFeatureFlag(
        string $key,
        ?string $distinctId = null,
        array $groups = [],
        array $personProperties = [],
        array $groupProperties = [],
        bool $onlyEvaluateLocally = false,
        bool $sendFeatureFlagEvents = true,
    ): bool|string|null {
        return $this->callFlagMethod(__FUNCTION__, func_get_args(), $distinctId);
    }

    /** @deprecated Use evaluateFlags() to read the value and payload from one snapshot. */
    public function getFeatureFlagResult(
        string $key,
        ?string $distinctId = null,
        array $groups = [],
        array $personProperties = [],
        array $groupProperties = [],
        bool $onlyEvaluateLocally = false,
        bool $sendFeatureFlagEvents = true,
    ): ?FeatureFlagResult {
        return $this->callFlagMethod(__FUNCTION__, func_get_args(), $distinctId);
    }

    /** @deprecated Use evaluateFlags()->getFlagPayload() instead. */
    public function getFeatureFlagPayload(
        string $key,
        ?string $distinctId = null,
        array $groups = [],
        array $personProperties = [],
        array $groupProperties = [],
    ): mixed {
        return $this->callFlagMethod(__FUNCTION__, func_get_args(), $distinctId);
    }

    public function getAllFlags(
        ?string $distinctId = null,
        array $groups = [],
        array $personProperties = [],
        array $groupProperties = [],
        bool $onlyEvaluateLocally = false,
    ): array {
        return $this->callFlagMethod(__FUNCTION__, func_get_args(), $distinctId);
    }

    public function loadFlags(): void
    {
        $this->client()->loadFlags();
    }

    public function contextFromHeaders(array $headers): array
    {
        return $this->client()->contextFromHeaders($headers);
    }

    private function callFlagMethod(string $method, array $arguments, ?string $distinctId): mixed
    {
        $data = $this->app->make(Context::class)->data();
        if ($distinctId === null || $distinctId === '') {
            $distinctId = $data['distinctId'] ?? null;
        }
        if ($this->enabled() && is_string($distinctId) && $distinctId !== '') {
            $this->loadDefinitionsIfNeeded();
        }
        return $this->client()->withContext(
            $data,
            fn () => $this->client()->$method(...$arguments),
            ['fresh' => true],
        );
    }
}
