<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\Consumer;
use PostHog\Internal\JsonEncoder;
use PostHog\Scripts\PublicApiSnapshot;
use ReflectionMethod;

class PublicApiSnapshotTest extends TestCase
{
    public function testInternalNamespaceIsExcludedWithoutDroppingExistingSymbols(): void
    {
        require_once __DIR__ . '/../scripts/PublicApiSnapshot.php';

        $builder = new ReflectionMethod(PublicApiSnapshot::class, 'buildPublicApi');
        $api = $builder->invoke(null, __DIR__ . '/../lib', 'PostHog\\');

        self::assertArrayHasKey(Client::class, $api['symbols']);
        self::assertArrayHasKey(Consumer::class, $api['symbols']);
        self::assertArrayNotHasKey(JsonEncoder::class, $api['symbols']);
        self::assertArrayNotHasKey('PostHog\\JsonEncoder', $api['symbols']);
    }
}
