<?php

namespace PostHog\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Throwable;

/** @internal The HTTP scope includes application termination and after-response jobs. */
final class RequestLifecycle
{
    private bool $active = false;

    public function __construct(private Container $app)
    {
    }

    public function begin(Request $request): void
    {
        $this->app->make(Context::class)->fromRequest($request);
        $this->active = true;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function finish(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        try {
            $this->app->make(PostHog::class)->flush();
        } catch (Throwable) {
            // Failed telemetry delivery must not prevent scope cleanup.
        } finally {
            $this->app->make(Context::class)->end();
        }
    }
}
