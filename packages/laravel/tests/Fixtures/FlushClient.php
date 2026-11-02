<?php

namespace PostHog\Laravel\Tests\Fixtures;

use PostHog\Client;
use RuntimeException;

class FlushClient extends Client
{
    public int $flushes = 0;
    public bool $fail = false;

    public function __construct()
    {
        parent::__construct(null, ['consumer' => 'noop'], loadFeatureFlags: false);
    }

    public function flush(): bool
    {
        $this->flushes++;
        if ($this->fail) {
            throw new RuntimeException('transport failure');
        }
        return true;
    }
}
