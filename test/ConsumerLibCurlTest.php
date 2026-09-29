<?php

namespace PostHog\Test;

class ConsumerLibCurlTest extends ConsumerTransportTestCase
{
    protected function consumer(): string
    {
        return 'lib_curl';
    }
}
