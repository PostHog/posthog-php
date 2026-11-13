<?php

namespace PostHog\Laravel\Tests;

use LogicException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHogServiceProvider;

/**
 * The package builds no mailer and loads no Symfony Mailer class until an app sends mail, so an app
 * that sends no mail pays nothing for the posthog mailer.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class MailDriverWithoutMailerTest extends TestCase
{
    protected function setUp(): void
    {
        spl_autoload_register(static function (string $class): void {
            // Laravel itself loads Illuminate\Mail's service provider, but nothing builds a mailer.
            foreach (['Symfony\\Component\\Mailer\\', 'Symfony\\Component\\Mime\\', 'PostHog\\Mailer\\'] as $prefix) {
                if (str_starts_with($class, $prefix)) {
                    throw new LogicException("Booting the package loaded $class.");
                }
            }
        }, true, true);
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'phc_project');
        $app['config']->set('posthog.sdk.consumer', 'noop');
    }

    public function testThePackageBootsAndCapturesWithoutLoadingAMailer(): void
    {
        $fake = PostHog::fake();

        PostHog::capture('order completed', [], 'user_123');

        $fake->assertCaptured('order completed');
    }
}
