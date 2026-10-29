<?php

namespace PostHog\Laravel\Tests\Fixtures;

class OrderCompleted
{
    public function __construct(
        public int $amount = 42,
        public string $email = 'secret@example.test',
        public array $details = ['currency' => 'USD'],
        public mixed $model = null,
    ) {
    }
}
