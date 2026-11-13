<?php

namespace PostHog\Test\Mailer;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use PostHog\Mailer\PostHogTransportFactory;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;

final class PostHogTransportFactoryTest extends TestCase
{
    use SendsThroughStubbedHttp;

    private function send(string $dsn): void
    {
        $this->posthog = new FakePostHog('phs_dsn');
        $factory = new PostHogTransportFactory(sendRequest: Closure::fromCallable($this->posthog));
        (new Transport([$factory]))->fromString($dsn)->send(
            (new Email())->from('shop@example.com')->to('ana@example.com')->subject('Hi')->text('Hi')
        );
    }

    public static function hosts(): array
    {
        return [
            'default is US Cloud' => ['posthog+api://phs_dsn@default', 'https://us.posthog.com'],
            'EU Cloud' => ['posthog+api://phs_dsn@eu.posthog.com', 'https://eu.posthog.com'],
            'a self-hosted PostHog with a port' => [
                'posthog+api://phs_dsn@posthog.example.com:8443',
                'https://posthog.example.com:8443',
            ],
        ];
    }

    #[DataProvider('hosts')]
    public function testTheDsnSendsWithItsKeyToItsHost(string $dsn, string $appHost): void
    {
        $this->send($dsn);

        $this->assertSame($appHost . '/api/projects/@current/messaging/send/email/', $this->requests()[0]['url']);
        $this->assertSame('Bearer phs_dsn', $this->header('Authorization'));
    }

    public function testTheDsnSetsTheDefaultTemplateKey(): void
    {
        $this->send('posthog+api://phs_dsn@default?default_template_key=transactional');

        $this->assertSame('transactional', $this->body()['template_key']);
    }

    public function testAnotherSchemeIsNotPostHogs(): void
    {
        $factory = new PostHogTransportFactory();

        $this->assertFalse($factory->supports(Dsn::fromString('posthog+smtp://phs_dsn@default')));
        $this->expectException(UnsupportedSchemeException::class);
        $factory->create(Dsn::fromString('posthog+smtp://phs_dsn@default'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheFactoryStepsAsideForSymfonysPostHogBridge(): void
    {
        $factory = new PostHogTransportFactory();
        $this->assertTrue($factory->supports(Dsn::fromString('posthog+api://phs_dsn@default')));

        class_alias(
            PostHogTransportFactory::class,
            'Symfony\Component\Mailer\Bridge\PostHog\Transport\PostHogTransportFactory'
        );

        $this->assertFalse($factory->supports(Dsn::fromString('posthog+api://phs_dsn@default')));
    }
}
