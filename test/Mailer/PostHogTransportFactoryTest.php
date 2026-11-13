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
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
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

    public static function appCredentials(): array
    {
        return [
            'a bearer token' => [['auth_bearer' => 'app-token'], 'app-token'],
            'basic auth' => [['auth_basic' => 'app:secret'], base64_encode('app:secret')],
            'an Authorization header' => [['headers' => ['Authorization' => 'Token app-token']], 'app-token'],
        ];
    }

    /**
     * @param array<string, mixed> $defaults
     */
    #[DataProvider('appCredentials')]
    public function testTheAppsHttpClientCredentialsStayOffEveryRequest(array $defaults, string $credential): void
    {
        $this->posthog = new FakePostHog('phs_dsn');
        $client = $this->posthog->httpClient()->withOptions($defaults);
        $factory = new PostHogTransportFactory(client: $client);

        (new Transport([$factory]))->fromString('posthog+api://phs_dsn@default')
            ->send(self::email()->attach("%PDF-1.4\n", 'invoice.pdf', 'application/pdf'));

        $this->assertCount(1, $this->posthog->queued);
        foreach ($this->posthog->requests as $request) {
            $this->assertStringNotContainsString($credential, implode("\n", $request['headers']), $request['url']);
        }
    }

    public function testTheAppsRetryingHttpClientLeavesRetriesToTheSdk(): void
    {
        $previousClock = Clock::get();
        Clock::set(new MockClock('2026-11-13 12:00:00'));
        $busy = new MockHttpClient(static fn(): MockResponse => new MockResponse('', ['http_code' => 503]));
        $client = new RetryableHttpClient($busy, new GenericRetryStrategy(delayMs: 1), 3);
        $transport = (new Transport([new PostHogTransportFactory(client: $client)]))
            ->fromString('posthog+api://phs_dsn@default');

        try {
            $transport->send(self::email());
            $this->fail('The send did not fail.');
        } catch (PostHogTransportException $error) {
            $this->assertTrue($error->isRetryable());
            $this->assertSame(3, $busy->getRequestsCount(), 'Only the SDK retries the send.');
        } finally {
            Clock::set($previousClock);
        }
    }

    public function testAClientWithoutRetriesOfItsOwnSendsAsItIs(): void
    {
        $previousClock = Clock::get();
        Clock::set(new MockClock('2026-11-13 12:00:00'));
        $transport = (new Transport([new PostHogTransportFactory(client: new NativeHttpClient())]))
            ->fromString('posthog+api://phs_dsn@127.0.0.1:1');

        try {
            $transport->send(self::email());
            $this->fail('The send did not fail.');
        } catch (PostHogTransportException $error) {
            $this->assertSame('network_error', $error->getErrorCode());
            $this->assertStringNotContainsString('max_retries', $error->getMessage());
        } finally {
            Clock::set($previousClock);
        }
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

    public function testABusyPostHogIsRetriedThroughTheAppsHttpClient(): void
    {
        $previousClock = Clock::get();
        Clock::set(new MockClock('2026-11-13 12:00:00'));
        $this->posthog = new FakePostHog('phs_dsn');
        $busy = ['type' => 'server_error', 'code' => 'service_unavailable', 'detail' => 'Busy.', 'attr' => null];
        $this->posthog->sendReplies = [[503, json_encode($busy), ['Retry-After: 1']]];
        $transport = (new Transport([new PostHogTransportFactory(client: $this->posthog->httpClient())]))
            ->fromString('posthog+api://phs_dsn@default');

        try {
            $transport->send(self::email());
        } finally {
            Clock::set($previousClock);
        }

        $this->assertCount(2, $this->posthog->sends());
        $this->assertCount(1, $this->posthog->queued);
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
