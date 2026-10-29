<?php

namespace PostHog\Laravel\Autocapture;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class ModelEvents
{
    public function collect(string $operation, Model $model): void
    {
        try {
            if (!Collector::enabled('models')) {
                return;
            }
            $models = Container::getInstance()['config']->get('posthog.autocapture.models.allow', []);
            if (!array_key_exists($model::class, $models) || !is_array($models[$model::class])) {
                return;
            }
            $attributes = [];
            $stored = $model->getAttributes();
            foreach ($models[$model::class] as $key) {
                if (is_string($key) && array_key_exists($key, $stored)) {
                    $value = $model->getAttribute($key);
                    if (Collector::jsonSafe($value)) {
                        $attributes[$key] = $value;
                    }
                }
            }
            $properties = ['model' => $model::class, 'attributes' => $attributes];
            $snapshot = Collector::snapshot();
            // Register with this model's connection; no model or payload survives until commit.
            $model->getConnection()->afterCommit(static function () use ($operation, $properties, $snapshot) {
                Collector::capture('laravel_model_' . $operation, $properties, $snapshot);
            });
        } catch (Throwable) {
        }
    }
}
