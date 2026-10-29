<?php

namespace PostHog\Laravel;

use Illuminate\Contracts\Container\Container;
use PostHog\Client;
use PostHog\FeatureFlagEvaluations;
use Throwable;

class PostHog
{
    public const VERSION = '0.1.0';

    private ?Client $sdk = null;
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
        if ($this->sdk !== null) {
            return $this->sdk;
        }
        if (!$this->enabled()) {
            return $this->sdk = new Client(null, ['consumer' => 'noop'], loadFeatureFlags: false);
        }
        $config = $this->app['config']->get('posthog', []);
        $options = $config['sdk'] ?? [];
        $options += ['disableGeoip' => true];
        $options['host'] = $config['host'] ?? 'https://us.i.posthog.com';
        $options['library_name'] = 'posthog-laravel';
        $options['library_version'] = self::VERSION;
        if (($config['release_id'] ?? null) !== null) {
            $options['release_id'] = $config['release_id'];
        }
        // Laravel owns reporting and process-wide PHP handlers.
        $options['error_tracking'] = array_replace($options['error_tracking'] ?? [], [
            'enabled' => false,
        ]);
        foreach (['before_send', 'error_handler', 'flag_definition_cache_provider'] as $key) {
            if (isset($options[$key]) && is_string($options[$key])) {
                $options[$key] = $this->app->make($options[$key]);
            }
        }
        if (!isset($options['flag_definition_cache_provider']) && ($config['flag_cache']['enabled'] ?? false)) {
            $options['flag_definition_cache_provider'] = $this->app->make(FlagDefinitionCacheProvider::class);
        }
        return $this->sdk = new Client(
            $config['api_key'],
            $options,
            loadFeatureFlags: false,
            secretKey: $config['secret_key'] ?? null,
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
        return $context->flags(hash('sha256', serialize($inputs)), function () use ($inputs, $flagKeys) {
            if ($flagKeys !== []) {
                $this->loadDefinitionsIfNeeded();
            }
            return $this->client()->evaluateFlags(...$inputs);
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

    /** @internal Report a Laravel failure using its originating boundary snapshot. */
    public function reportException(Throwable $exception, array $snapshot, array $options): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $properties = isset($snapshot['groups']) ? ['$groups' => $snapshot['groups']] : [];
        return $this->client()->withContext(
            $snapshot,
            fn () => $this->client()->captureException($exception, null, $properties, $options),
            ['fresh' => true],
        );
    }

    public function flush(): bool
    {
        return $this->sdk !== null ? (bool) $this->sdk->flush() : false;
    }

    public function shutdown(): bool
    {
        return $this->sdk !== null ? $this->sdk->shutdown() : false;
    }

    public function __call(string $method, array $arguments): mixed
    {
        $data = $this->app->make(Context::class)->data();
        $legacyFlags = [
            'getAllFlags' => 0,
            'getFeatureFlagResult' => 1,
            'getFeatureFlag' => 1,
            'getFeatureFlagPayload' => 1,
            'isFeatureEnabled' => 1,
        ];
        if ($this->enabled() && isset($legacyFlags[$method])) {
            $distinctId = $arguments['distinctId'] ?? $arguments[$legacyFlags[$method]] ?? null;
            if ($distinctId === null || $distinctId === '') {
                $distinctId = $data['distinctId'] ?? null;
            }
            if (is_string($distinctId) && $distinctId !== '') {
                $this->loadDefinitionsIfNeeded();
            }
        }
        return $this->client()->withContext(
            $data,
            fn () => $this->client()->$method(...$arguments),
            ['fresh' => true],
        );
    }
}
