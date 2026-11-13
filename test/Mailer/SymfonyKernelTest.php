<?php

namespace PostHog\Test\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mime\Email;

final class SymfonyKernelTest extends TestCase
{
    private MailerKernel $kernel;

    protected function setUp(): void
    {
        RecordedRequests::$requests = [];
        $this->kernel = new MailerKernel('test', false);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        (new Filesystem())->remove($this->kernel->getProjectDir() . '/var');
    }

    public function testAnAppWithThePostHogDsnSendsItsEmailsThroughPostHog(): void
    {
        $mailer = $this->kernel->getContainer()->get('test.mailer');

        $mailer->send((new Email())
            ->from('shop@example.com')
            ->to('ana@example.com')
            ->subject('Your order shipped')
            ->html('<p>On its way.</p>'));

        $this->assertCount(1, RecordedRequests::$requests);
        $request = RecordedRequests::$requests[0];
        $this->assertSame('https://us.posthog.com/api/projects/@current/messaging/send/email/', $request['url']);
        $this->assertContains('Authorization: Bearer phs_kernel', $request['headers']);
        $this->assertSame('ana@example.com', json_decode($request['body'], true)['to']);
    }
}
