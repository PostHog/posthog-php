<?php

namespace PostHog\Laravel\Features;

use Illuminate\Container\Container;
use Laravel\Pennant\FeatureManager;

/** @internal Pennant caches values independently of the SDK's scoped snapshots. */
final class PennantCache
{
    public static function flush(): void
    {
        $app = Container::getInstance();
        if (!$app->resolved(FeatureManager::class)) {
            return;
        }
        $manager = $app->make(FeatureManager::class);
        $manager->setContainer($app);
        foreach ($app['config']->get('pennant.stores', []) as $name => $store) {
            if (($store['driver'] ?? null) === 'posthog') {
                $manager->store($name)->flushCache();
            }
        }
    }
}
