<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Orchestra\Testbench\TestCase;
use PostHog\Laravel\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\InvoicePaid;
use PostHog\Laravel\Tests\Fixtures\OrderShipped;
use PostHog\Laravel\Tests\Fixtures\SendApiStub;
use PostHog\Mailer\PostHogTransportException;
use Symfony\Component\Mailer\Exception\LogicException;
use Symfony\Component\Mailer\RemoteTemplateEmail;

class MailDriverTest extends TestCase
{
    private const MESSAGE_ID = '01a18356-c56c-0001-e494-7666bc915188';
    private const SENT = ['status' => 202, 'body' => '{"message_id":"' . self::MESSAGE_ID . '","replayed":false}'];

    private ?SendApiStub $api = null;

    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'phc_project');
        $app['config']->set('posthog.secret_key', 'phs_secret');
        $app['config']->set('posthog.sdk.consumer', 'noop');
        $app['config']->set('mail.default', 'posthog');
        $app['config']->set('mail.mailers.posthog', ['transport' => 'posthog']);
    }

    protected function tearDown(): void
    {
        $this->api?->stop();
        parent::tearDown();
    }

    /**
     * @param list<array{status: int, body?: string, headers?: array<string, string>}> $responses
     */
    private function sendApi(array $responses = [self::SENT]): SendApiStub
    {
        $this->api = new SendApiStub($responses);
        $this->app['config']->set('posthog.sdk.app_host', $this->api->url());

        return $this->api;
    }

    /**
     * @return array<string, mixed>
     */
    private function onlySend(): array
    {
        $requests = $this->api->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST /api/projects/@current/messaging/send/email/ HTTP/1.1', $requests[0]['requestLine']);
        $this->assertSame('Bearer phs_secret', $requests[0]['headers']['authorization']);

        return $requests[0];
    }

    public function testAMailableSendsThroughThePostHogMailerUnderItsClassName(): void
    {
        $this->sendApi();

        $sent = Mail::to('ana@example.com')->send(new OrderShipped());

        $request = $this->onlySend();
        $this->assertSame('laravel/' . PostHog::VERSION, $request['headers']['x-posthog-integration']);
        $this->assertSame([
            'template_key' => 'order-shipped',
            'subject' => 'Your order shipped',
            'html' => '<p>On its way.</p>',
            'from' => 'shop@example.com',
            'to' => 'ana@example.com',
        ], array_diff_key(json_decode($request['body'], true), ['capture_engagement' => true]));
        $this->assertSame(self::MESSAGE_ID, $sent->getMessageId());
    }

    public function testAMailNotificationSendsUnderTheNotificationClassName(): void
    {
        $this->sendApi();

        Notification::send(
            (new AnonymousNotifiable())->route('mail', 'ana@example.com'),
            new InvoicePaid()
        );

        $body = json_decode($this->onlySend()['body'], true);
        $this->assertSame('invoice-paid', $body['template_key']);
        $this->assertSame('Invoice paid', $body['subject']);
        $this->assertSame('ana@example.com', $body['to']);
    }

    public function testTheMailableSetsItsOwnTemplateKeyAndOptionsInHeaders(): void
    {
        $this->sendApi();

        Mail::to('ana@example.com')->send(new OrderShipped([
            'X-PostHog-Template-Key' => 'shipping-v2',
            'X-PostHog-Distinct-Id' => 'user_123',
            'X-PostHog-Idempotency-Key' => 'order-42:order-shipped',
        ]));

        $request = $this->onlySend();
        $body = json_decode($request['body'], true);
        $this->assertSame('shipping-v2', $body['template_key']);
        $this->assertSame('user_123', $body['distinct_id']);
        $this->assertSame('order-42:order-shipped', $request['headers']['idempotency-key']);
    }

    public function testMailWithNoClassSendsUnderTheMailersDefaultTemplateKey(): void
    {
        $this->app['config']->set('mail.mailers.posthog.default_template_key', 'receipts');
        $this->sendApi();

        Mail::raw('Thanks for your order.', function ($message) {
            $message->from('shop@example.com')->to('ana@example.com')->subject('Receipt');
        });

        $this->assertSame('receipts', json_decode($this->onlySend()['body'], true)['template_key']);
    }

    public function testMailWithNoClassAndNoDefaultSendsUnderTheIntegrationName(): void
    {
        $this->sendApi();

        Mail::raw('Thanks for your order.', function ($message) {
            $message->from('shop@example.com')->to('ana@example.com')->subject('Receipt');
        });

        $this->assertSame('laravel', json_decode($this->onlySend()['body'], true)['template_key']);
    }

    public function testAnotherMailerSendsTheMailableWithoutPostHogHeaders(): void
    {
        $this->app['config']->set('mail.mailers.archive', ['transport' => 'array']);

        Mail::mailer('archive')->to('ana@example.com')->send(new OrderShipped());

        $messages = Mail::mailer('archive')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertFalse($messages[0]->getOriginalMessage()->getHeaders()->has('X-PostHog-Template-Key'));
    }

    public function testAMailablePostHogCannotCarryIsRefusedWithoutASend(): void
    {
        $this->sendApi();

        try {
            Mail::to(['ana@example.com', 'ben@example.com'])->send(new OrderShipped());
            $this->fail('A mail to two recipients was sent.');
        } catch (LogicException $refusal) {
            $this->assertStringStartsWith('PostHog cannot send this email: multiple_to', $refusal->getMessage());
        }
        $this->assertSame([], $this->api->requests());
    }

    public function testAFailedSendThrowsTheTransportErrorWithItsRetryAdvice(): void
    {
        $this->sendApi([[
            'status' => 400,
            'body' => '{"type":"validation_error","code":"invalid_input","detail":"Bad from.","attr":"from"}',
        ]]);

        try {
            Mail::to('ana@example.com')->send(new OrderShipped());
            $this->fail('A rejected send did not throw.');
        } catch (PostHogTransportException $error) {
            $this->assertSame(400, $error->getStatusCode());
            $this->assertSame('from', $error->getField());
            $this->assertFalse($error->isRetryable());
        }
    }

    public function testATemplateMadeInPostHogIsRefused(): void
    {
        if (!class_exists(RemoteTemplateEmail::class)) {
            $this->markTestSkipped('Symfony Mailer before 8.2 has no RemoteTemplateEmail to send a template.');
        }
        $this->sendApi();
        $email = (new RemoteTemplateEmail())
            ->from('shop@example.com')
            ->to('ana@example.com')
            ->template('0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b');

        try {
            Mail::mailer('posthog')->getSymfonyTransport()->send($email);
            $this->fail('A template was sent.');
        } catch (LogicException $refusal) {
            $this->assertStringStartsWith(
                'PostHog cannot send this email: template_unsupported',
                $refusal->getMessage()
            );
        }
        $this->assertSame([], $this->api->requests());
    }
}
