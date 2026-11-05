<?php

namespace PostHog\Test\Mailer;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\Emails;

/**
 * symfony/mailer is only a suggestion, so an app without it must still send email with the core SDK.
 */
final class CoreWithoutSymfonyMailerTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheCoreSdkSendsEmailWithoutSymfonyMailerInstalled(): void
    {
        spl_autoload_register(static function (string $class): void {
            if (
                str_starts_with($class, 'Symfony\\Component\\Mailer\\')
                || str_starts_with($class, 'Symfony\\Component\\Mime\\')
            ) {
                throw new LogicException("The core SDK loaded $class.");
            }
        }, true, true);

        $client = new Client(
            'phc_project',
            ['host' => 'us.i.posthog.com'],
            loadFeatureFlags: false,
            secretKey: 'phs_secret'
        );
        $this->assertInstanceOf(Emails::class, $client->emails());

        $emails = new Emails(
            'phs_secret',
            'us.i.posthog.com',
            transport: static fn(): array => [202, '{"message_id":"01a18356-c56c-0001-e494-7666bc915188"}', []]
        );
        $result = $emails->send([
            'to' => 'ana@example.com',
            'from' => 'shop@example.com',
            'template_key' => 'order-shipped',
            'subject' => 'Your order shipped',
            'text' => 'On its way.',
        ]);

        $this->assertSame('01a18356-c56c-0001-e494-7666bc915188', $result->getId());
    }
}
