<?php

namespace PostHog\Laravel\Concerns;

use Illuminate\Container\Container;
use PostHog\Laravel\ConsoleLifecycle;
use Symfony\Component\Console\Output\OutputInterface;

/** Apply to the calling command to isolate its child executions, not its own root lifetime. */
trait IsolatesCommandContext
{
    protected function runCommand($command, array $arguments, OutputInterface $output)
    {
        return Container::getInstance()->make(ConsoleLifecycle::class)->call(
            fn () => parent::runCommand($command, $arguments, $output),
        );
    }
}
