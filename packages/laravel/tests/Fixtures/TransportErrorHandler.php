<?php

namespace PostHog\Laravel\Tests\Fixtures;

class TransportErrorHandler
{
    public function __invoke(int $code, string $message): void
    {
    }
}
