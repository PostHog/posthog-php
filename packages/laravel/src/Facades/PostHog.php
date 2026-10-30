<?php

namespace PostHog\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use PostHog\Laravel\Testing\PostHogFake;

/** @mixin \PostHog\Laravel\PostHog */
class PostHog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \PostHog\Laravel\PostHog::class;
    }

    public static function fake(): PostHogFake
    {
        $fake = new PostHogFake(static::$app);
        static::swap($fake);
        static::$app->instance(\PostHog\Client::class, $fake->client());
        return $fake;
    }
}
