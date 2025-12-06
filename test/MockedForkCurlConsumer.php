<?php

namespace PostHog\Test;

use PostHog\Consumer\ForkCurl;

class MockedForkCurlConsumer extends ForkCurl
{
    public array $commands = [];
    public int $fakeExit = 0;

    protected function runCommand(string $cmd, ?array &$output, ?int &$exit): void
    {
        $this->commands[] = $cmd;
        $output = [];
        $exit = $this->fakeExit;
    }
}
