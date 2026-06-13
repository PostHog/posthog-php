<?php

namespace PostHog\Test;

use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;

/**
 * PSR-16 exception used by ArrayCache to simulate a failing cache backend in tests.
 */
class InvalidCacheKey extends RuntimeException implements InvalidArgumentException
{
}
