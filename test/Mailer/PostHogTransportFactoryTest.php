<?php

namespace PostHog\Test\Mailer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use PostHog\Mailer\PostHogTransportException;
use PostHog\Mailer\PostHogTransportFactory;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;

final class PostHogTransportFactoryTest extends TestCase
{
    use SendsThroughStubbedHttp;

    private function send(string $dsn, ?Email $email = null): void
    {
        $this->posthog = new FakePostHog('phs_dsn');
        $factory = new PostHogTransportFactory(client: $this->posthog->httpClient());
        (new Transport([$factory]))->fromString($dsn)->send($email ?? self::email());
    }

    private static function email(): Email
    {
        return (new Email())->from('shop@example.com')->to('ana@example.com')->subject('Hi')->text('Hi');
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

    public function testAFileUploadsThroughTheAppsHttpClient(): void
    {
        $pdf = "%PDF-1.4\n";

        $this->send('posthog+api://phs_dsn@default', self::email()->attach($pdf, 'invoice.pdf', 'application/pdf'));

        $this->assertSame([hash('sha256', $pdf) => $pdf], $this->posthog->stored);
        $this->assertCount(1, $this->posthog->queued);
    }

    public function testAFailureOfTheAppsHttpClientIsANetworkErrorToRetry(): void
    {
        $previousClock = Clock::get();
        Clock::set(new MockClock('2026-11-13 12:00:00'));
        $unreachable = new MockHttpClient(
            static fn(): MockResponse => new MockResponse('', ['error' => 'Could not resolve host: us.posthog.com'])
        );
        $transport = (new Transport([new PostHogTransportFactory(client: $unreachable)]))
            ->fromString('posthog+api://phs_dsn@default');

        try {
            $transport->send(self::email());
            $this->fail('The send did not fail.');
        } catch (PostHogTransportException $error) {
            $this->assertSame('network_error', $error->getErrorCode());
            $this->assertTrue($error->isRetryable());
            $this->assertSame(3, $unreachable->getRequestsCount());
        } finally {
            Clock::set($previousClock);
        }
    }

    public function testAnAnswerOverTheLimitStopsTheRead(): void
    {
        $flood = new MockHttpClient(new MockResponse(str_repeat('x', 1_048_577), ['http_code' => 202]));
        $transport = (new Transport([new PostHogTransportFactory(client: $flood)]))
            ->fromString('posthog+api://phs_dsn@default');

        try {
            $transport->send(self::email());
            $this->fail('The send did not fail.');
        } catch (PostHogTransportException $error) {
            $this->assertSame('response_too_large', $error->getErrorCode());
        }
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
