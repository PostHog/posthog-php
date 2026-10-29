<?php

namespace PostHog\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;

/** @internal Decorates the application's kernel without replacing its commands or exception policy. */
final class ConsoleKernel implements Kernel
{
    public function __construct(private Kernel $kernel)
    {
        if (method_exists($kernel, 'whenCommandLifecycleIsLongerThan')) {
            $kernel->whenCommandLifecycleIsLongerThan(-INF, static function () {
                Container::getInstance()->make(ConsoleLifecycle::class)->finishRoot();
            });
        }
    }

    public function bootstrap()
    {
        return $this->kernel->bootstrap();
    }

    public function handle($input, $output = null)
    {
        return Container::getInstance()->make(ConsoleLifecycle::class)->handle(
            fn () => $this->kernel->handle($input, $output),
        );
    }

    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        return Container::getInstance()->make(ConsoleLifecycle::class)->call(
            fn () => $this->kernel->call($command, $parameters, $outputBuffer),
        );
    }

    public function queue($command, array $parameters = [])
    {
        return $this->kernel->queue($command, $parameters);
    }

    public function all()
    {
        return $this->kernel->all();
    }

    public function output()
    {
        return $this->kernel->output();
    }

    public function terminate($input, $status)
    {
        try {
            return $this->kernel->terminate($input, $status);
        } finally {
            Container::getInstance()->make(ConsoleLifecycle::class)->finishRoot();
        }
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->kernel->$method(...$arguments);
    }
}
