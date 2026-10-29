<?php

namespace PostHog\Laravel;

use Illuminate\Contracts\Container\Container;
use Throwable;

/** @internal Keep root commands through termination; nested calls restore their caller immediately. */
final class ConsoleLifecycle
{
    private bool $root = false;
    private int $calls = 0;

    public function __construct(private Container $app)
    {
    }

    public function startRoot(): void
    {
        if ($this->root || $this->calls > 0) {
            return;
        }
        $this->enter();
        $this->root = true;
    }

    public function handle(callable $callback): mixed
    {
        $this->startRoot();
        try {
            return $callback();
        } catch (Throwable $exception) {
            $this->remember($exception);
            $this->finishRoot();
            throw $exception;
        }
    }

    public function call(callable $callback): mixed
    {
        $this->enter();
        $this->calls++;
        try {
            return $callback();
        } catch (Throwable $exception) {
            $this->remember($exception);
            throw $exception;
        } finally {
            $this->calls--;
            $this->leave();
        }
    }

    public function finishRoot(): void
    {
        if ($this->root) {
            $this->root = false;
            $this->leave();
        }
    }

    private function enter(): void
    {
        $context = $this->app->make(Context::class);
        $data = $context->snapshot();
        $context->enter();
        $context->begin($data);
    }

    private function remember(Throwable $exception): void
    {
        try {
            $this->app->make(ExceptionReporter::class)->remember($exception, 'runtime');
        } catch (Throwable) {
        }
    }

    private function leave(): void
    {
        try {
            $this->app->make(PostHog::class)->flush();
        } catch (Throwable) {
        } finally {
            $this->app->make(Context::class)->leave();
        }
    }
}
