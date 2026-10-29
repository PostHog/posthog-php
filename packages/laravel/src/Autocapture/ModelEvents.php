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
            $snapshot = Collector::snapshot();
            $id = $snapshot['distinctId'] ?? null;
            if (!is_scalar($id) || (string) $id === '' || !Collector::jsonSafe($snapshot)) {
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
            $connection = $model->getConnection();
            $event = 'laravel_model_' . $operation;
            if ($connection->transactionLevel() === 0) {
                Collector::capture($event, $properties, $snapshot);
                return;
            }
            Container::getInstance()->make(DeferredModelEvents::class)->defer(
                $connection,
                $event,
                $properties,
                $snapshot,
            );
        } catch (Throwable) {
        }
    }
}
