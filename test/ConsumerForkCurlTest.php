<?php

namespace PostHog\Test;

class ConsumerForkCurlTest extends ConsumerTransportTestCase
{
    protected function consumer(): string
    {
        return 'fork_curl';
    }
}
