<?php

namespace PostHog\Laravel;

use Illuminate\Contracts\Cache\Repository;
use PostHog\FlagDefinitionCacheProvider as Provider;

class FlagDefinitionCacheProvider implements Provider
{
    public function __construct(
        private Repository $cache,
        private string $key,
        private int $ttl = 300,
        private int $refreshInterval = 30,
    ) {
    }

    public function getFlagDefinitions(): ?array
    {
        $data = $this->cache->get($this->key);
        return is_array($data) ? $data : null;
    }

    public function shouldFetchFlagDefinitions(): bool
    {
        return $this->cache->add($this->key . ':refresh', true, $this->refreshInterval);
    }

    public function onFlagDefinitionsReceived(array $data): void
    {
        $this->cache->put($this->key, $data, $this->ttl);
    }

    public function shutdown(): void
    {
    }
}
