<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\EmailError;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmailsWithoutCurlTest extends TestCase
{
    public function testWithoutTheCurlExtensionSendAndGetThrowAnEmailError(): void
    {
        require_once __DIR__ . '/no_curl_mock.php';
        $options = ['host' => 'ph.example.com', 'app_host' => 'http://127.0.0.1:9', 'consumer' => 'noop'];
        $emails = (new Client('phc_key', $options, loadFeatureFlags: false, secretKey: 'phs_secret'))->emails();
        $errors = [];

        $calls = [
            fn() => $emails->send(['to' => 'user@example.com', 'template_id' => '0190f6d2-7c1e-7b3a-9f5e-1c2d3e4f5a6b']),
            fn() => $emails->get('01a18356-c56c-0001-e494-7666bc915188'),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('No EmailError was thrown.');
            } catch (EmailError $error) {
                $errors[] = [$error->getErrorCode(), $error->getErrorName(), $error->getFix()];
            }
        }

        $expected = ['missing_curl', 'application_error', 'Install or enable ext-curl.'];
        $this->assertSame([$expected, $expected], $errors);
    }
}
