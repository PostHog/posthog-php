<?php

namespace PostHog\Laravel\Tests\Fixtures;

use Closure;
use Illuminate\Console\Command;
use PostHog\Laravel\Concerns\IsolatesCommandContext;

class ScopedCommand extends Command
{
    use IsolatesCommandContext;

    public function __construct(string $signature, private Closure $action)
    {
        $this->signature = $signature;
        parent::__construct();
    }

    public function handle(): int
    {
        return ($this->action)($this);
    }
}
