<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\Client;
use ReflectionClass;

class ConsumerForkCurlTest extends TestCase
{
    private $client;

    public function setUp(): void
    {
        date_default_timezone_set("UTC");
        $this->client = new Client(
            "OnMMoZ6YVozrgSBeZ9FpkC0ixH0ycYZn",
            array(
                "consumer" => "fork_curl",
                "debug" => true,
            )
        );
    }

    public function testCapture(): void
    {
        self::assertTrue(
            $this->client->capture(
                array(
                    "distinctId" => "some-user",
                    "event" => "PHP Fork Curl'd\" Event",
                )
            )
        );
    }

    public function testIdentify(): void
    {
        self::assertTrue(
            $this->client->identify(
                array(
                    "distinctId" => "user-id",
                    "properties" => array(
                        "loves_php" => false,
                        "type" => "consumer fork-curl test",
                        "birthday" => time(),
                    ),
                )
            )
        );
    }

    public function testAlias(): void
    {
        self::assertTrue(
            $this->client->alias(
                array(
                    "alias" => "alias-id",
                    "distinctId" => "user-id",
                )
            )
        );
    }

    public function testConfigurePositiveTimeout(): void
    {
        $consumer = new MockedForkCurlConsumer(
            'test_api_key', 
            array(
                'consumer' => 'fork_curl',
                'debug' => true,
                'timeout' => 1500,
            )
        );

        $rcClient = new ReflectionClass(Client::class);
        $prop = $rcClient->getProperty('consumer');
        $prop->setAccessible(true);
        $prop->setValue($this->client, $consumer);

        self::assertTrue(
            $this->client->capture(
                array(
                    'distinctId' => 'some-user',
                    'event' => "PHP Fork Curl'd\" Event",
                ),
            )
        );

        $this->client->flush();

        self::assertNotEmpty($consumer->commands);
        $cmd = end($consumer->commands);
        self::assertStringContainsString('--max-time 2', $cmd);
        self::assertStringContainsString('--connect-timeout 2', $cmd);
    }

    public function testConfigureUnlimitedTimeout(): void
    {
        $consumer = new MockedForkCurlConsumer(
            'test_api_key', 
            array(
                'consumer' => 'fork_curl',
                'debug' => true,
                'timeout' => 0,
            )
        );

        $rcClient = new ReflectionClass(Client::class);
        $prop = $rcClient->getProperty('consumer');
        $prop->setAccessible(true);
        $prop->setValue($this->client, $consumer);

        self::assertTrue(
            $this->client->capture(
                array(
                    'distinctId' => 'some-user',
                    'event' => "PHP Fork Curl'd\" Event",
                ),
            )
        );

        $this->client->flush();

        self::assertNotEmpty($consumer->commands);
        $cmd = end($consumer->commands);
        self::assertStringNotContainsString('max-time', $cmd);
        self::assertStringNotContainsString('connect-timeout', $cmd);
    }
}
