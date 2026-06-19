<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;

use function PostHog\uuidV7;

class UuidTest extends TestCase
{
    public function testV7GeneratesValidVersionAndVariant(): void
    {
        $uuid = uuidV7();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    public function testV7EmbedsCurrentTimestamp(): void
    {
        $before = (int) floor(microtime(true) * 1000);
        $uuid = uuidV7();
        $after = (int) floor(microtime(true) * 1000);

        $timestamp = hexdec(substr(str_replace('-', '', $uuid), 0, 12));

        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function testV7GeneratesUniqueValues(): void
    {
        $uuids = [];

        for ($i = 0; $i < 100; ++$i) {
            $uuid = uuidV7();
            $this->assertArrayNotHasKey($uuid, $uuids);
            $uuids[$uuid] = true;
        }
    }
}
