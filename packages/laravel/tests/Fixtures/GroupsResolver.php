<?php

namespace PostHog\Laravel\Tests\Fixtures;

class GroupsResolver
{
    public function __invoke($app): array
    {
        return ['team' => 'resolved-team'];
    }
}
