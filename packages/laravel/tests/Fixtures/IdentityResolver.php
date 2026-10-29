<?php

namespace PostHog\Laravel\Tests\Fixtures;

class IdentityResolver
{
    public function __invoke($app): string
    {
        return 'resolved-user';
    }
}
