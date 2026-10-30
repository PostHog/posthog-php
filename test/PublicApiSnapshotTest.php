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
    public function testInternalAnnotationsCanBeExcludedWithoutChangingTheCoreDefault(): void
    {
        require_once __DIR__ . '/../scripts/PublicApiSnapshot.php';
        $directory = sys_get_temp_dir() . '/posthog-api-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $namespace = 'PostHog\\Test\\ApiFixture' . bin2hex(random_bytes(8));
        $file = $directory . '/Fixture.php';
        file_put_contents($file, sprintf(<<<'PHP'
<?php
namespace %s;
/** @internal */
class Hidden {}
class Visible {
    /** @internal */
    public const INTERNAL = 1;
    public const SUPPORTED = 2;
    /** @internal */
    public int $internal = 0;
    public int $supported = 0;
    /** @internal */
    public function internal(): void {}
    public function supported(): void {}
}
PHP, $namespace));
        try {
            $builder = new ReflectionMethod(PublicApiSnapshot::class, 'buildPublicApi');
            $api = $builder->invoke(null, $directory, $namespace . '\\', true);
            self::assertArrayNotHasKey($namespace . '\\Hidden', $api['symbols']);
            $visible = $api['symbols'][$namespace . '\\Visible'];
            self::assertSame(['SUPPORTED'], array_keys($visible['constants']));
            self::assertSame(['supported'], array_keys($visible['properties']));
            self::assertSame(['supported'], array_keys($visible['methods']));
            $unfiltered = $builder->invoke(null, $directory, $namespace . '\\');
            self::assertArrayHasKey($namespace . '\\Hidden', $unfiltered['symbols']);
            self::assertArrayHasKey('internal', $unfiltered['symbols'][$namespace . '\\Visible']['methods']);
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

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
