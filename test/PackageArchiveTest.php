<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;

class PackageArchiveTest extends TestCase
{
    public function testCoreDistributionExcludesTheLaravelPackage(): void
    {
        self::assertFileExists(__DIR__ . '/../packages/laravel/composer.json');
        self::assertFileExists(__DIR__ . '/../packages/laravel/src/PostHog.php');
        $command = 'git -C ' . escapeshellarg(dirname(__DIR__))
            . ' archive --format=tar --worktree-attributes HEAD | tar -tf -';
        exec($command, $entries, $exitCode);

        self::assertSame(0, $exitCode);
        self::assertContains('composer.json', $entries);
        self::assertContains('lib/Client.php', $entries);
        self::assertContains('LICENSE.md', $entries);
        self::assertSame([], array_values(array_filter(
            $entries,
            static fn ($path) => $path === 'packages/laravel' || str_starts_with($path, 'packages/laravel/'),
        )));
    }
}
