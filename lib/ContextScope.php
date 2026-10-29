<?php

namespace PostHog;

use Closure;

/**
 * Owns an entered context until close() or destruction.
 *
 * Retain the object returned by enterContext(): discarding it immediately closes the scope.
 */
final class ContextScope
{
    /** @internal Obtain scopes through Client::enterContext(). */
    public function __construct(private ?Closure $onClose)
    {
    }

    /** Release this scope once; repeated calls are harmless and younger scopes remain active. */
    public function close(): void
    {
        $close = $this->onClose;
        $this->onClose = null;
        if ($close !== null) {
            $close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
