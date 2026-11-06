<?php

namespace PostHog\Laravel\Delivery;

use Closure;

/** @internal Owns event capacity until a deferred callback completes or is discarded. */
final class DeferredCapacity
{
    public function __construct(private ?Closure $onRelease)
    {
    }

    public function release(): void
    {
        $release = $this->onRelease;
        $this->onRelease = null;
        if ($release !== null) {
            $release();
        }
    }

    public function __destruct()
    {
        // Rollback can discard callbacks without invoking them, including committed savepoints.
        $this->release();
    }
}
