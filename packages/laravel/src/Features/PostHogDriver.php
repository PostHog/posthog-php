<?php

namespace PostHog\Laravel\Features;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Laravel\Pennant\Contracts\Driver;
use LogicException;
use PostHog\Laravel\PostHog;

final class PostHogDriver implements Driver
{
    public function get(string $feature, mixed $scope): mixed
    {
        $id = $this->identity($scope);
        return Container::getInstance()->make(PostHog::class)->evaluateFlags($id)->getFlag($feature) ?? false;
    }

    public function getAll(array $features): array
    {
        $values = [];
        foreach ($features as $feature => $scopes) {
            $values[$feature] = [];
            foreach ($scopes as $key => $scope) {
                $values[$feature][$key] = $this->get($feature, $scope);
            }
        }
        return $values;
    }

    public function defined(): array
    {
        return Container::getInstance()->make(PostHog::class)->evaluateFlags()->getKeys();
    }

    private function identity(mixed $scope): ?string
    {
        if ($scope === null) {
            return null;
        }
        if ($scope instanceof Authenticatable) {
            $scope = $scope->getAuthIdentifier();
        } elseif ($scope instanceof Model) {
            $scope = $scope->getKey();
        }
        if (is_string($scope) || is_int($scope)) {
            return (string) $scope;
        }
        throw new InvalidArgumentException('PostHog Pennant scopes must have a string or integer identifier.');
    }

    public function define(string $feature, callable $resolver): void
    {
        throw new LogicException('PostHog flags are read-only. Define them in PostHog.');
    }

    public function set(string $feature, mixed $scope, mixed $value): void
    {
        throw new LogicException('PostHog flags are read-only. Update them in PostHog.');
    }

    public function setForAllScopes(string $feature, mixed $value): void
    {
        throw new LogicException('PostHog flags are read-only. Update them in PostHog.');
    }

    public function delete(string $feature, mixed $scope): void
    {
        throw new LogicException('PostHog flags are read-only. Remove them in PostHog.');
    }

    public function purge(?array $features): void
    {
        throw new LogicException('PostHog flags are read-only. Remove them in PostHog.');
    }
}
