<?php

namespace PostHog\Test;

class ConsumerForkCurlTest extends ConsumerTransportTestCase
{
    use HttpRetryTransportTests;

    protected function consumer(): string
    {
        return 'fork_curl';
    }
}
