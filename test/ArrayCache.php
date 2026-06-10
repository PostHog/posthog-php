<?php

namespace PostHog\Test;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Clock\Clock;

/**
 * Minimal in-memory PSR-16 cache for tests. TTL is evaluated against Symfony Clock so expiry can be
 * driven by MockClock without real sleeps. Parameters are intentionally untyped so this single
 * implementation satisfies psr/simple-cache ^1, ^2, and ^3 (their interfaces differ in typing).
 *
 * Set $throwOnAccess to simulate a failing cache backend.
 */
class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expiresAt: int|null}> */
    private array $store = [];

    public bool $throwOnAccess = false;

    private function now(): int
    {
        return Clock::get()->now()->getTimestamp();
    }

    private function guard(): void
    {
        if ($this->throwOnAccess) {
            throw new InvalidCacheKey('simulated cache failure');
        }
    }

    public function get($key, $default = null): mixed
    {
        $this->guard();
        if (!array_key_exists($key, $this->store)) {
            return $default;
        }
        $entry = $this->store[$key];
        if ($entry['expiresAt'] !== null && $this->now() >= $entry['expiresAt']) {
            unset($this->store[$key]);
            return $default;
        }
        return $entry['value'];
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->guard();
        $expiresAt = null;
        if (is_int($ttl)) {
            $expiresAt = $this->now() + $ttl;
        } elseif ($ttl instanceof \DateInterval) {
            $expiresAt = $this->now() + ((new \DateTimeImmutable('@0'))->add($ttl)->getTimestamp());
        }
        $this->store[$key] = ['value' => $value, 'expiresAt' => $expiresAt];
        return true;
    }

    public function delete($key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has($key): bool
    {
        $this->guard();
        return $this->get($key, $this) !== $this;
    }
}
