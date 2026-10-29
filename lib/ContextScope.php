<?php

namespace PostHog;

use Closure;

final class ContextScope
{
    /** @internal Obtain scopes through Client::enterContext(). */
    public function __construct(private ?Closure $onClose)
    {
    }

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
