<?php

namespace PostHog\Laravel\Tests\Fixtures;

class CaptureCallback
{
    public array $events = [];

    public function __invoke(array $event): ?array
    {
        $this->events[] = $event;
        return null;
    }
}
