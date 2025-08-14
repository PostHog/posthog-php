<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\Consumer\LibCurl;
use ReflectionClass;

class ConsumerLibCurlTest extends TestCase
{
    private $client;

    public function setUp(): void
    {
        date_default_timezone_set("UTC");
        $this->client = new Client(
            "BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg",
            [
                "consumer" => "lib_curl",
                "debug" => true,
            ]
        );
    }

    public function testApplyConstructOptionsToHttpClient()
    {
        $client = new Client(
            'test_api_key',
            array(
                'consumer' => 'lib_curl',
                'ssl' => false,
                'maximum_backoff_duration' => 5000,
                'compress_request' => true,
                'debug' => true,
                'timeout' => 1234,
            )
        );

        $rcClient = new ReflectionClass($client);
        $consumerProp = $rcClient->getProperty('consumer');
        $consumerProp->setAccessible(true);
        $consumer = $consumerProp->getValue($client);

        $this->assertInstanceOf(LibCurl::class, $consumer);

        $rcConsumer = new ReflectionClass($consumer);
        $httpProp = $rcConsumer->getProperty('httpClient');
        $httpProp->setAccessible(true);
        $httpClient = $httpProp->getValue($consumer);

        $rcHttp = new ReflectionClass($httpClient);

        $expectedValues = array(
            'useSsl' => false,
            'maximumBackoffDuration' => 5000,
            'compressRequests' => true,
            'debug' => true,
            'errorHandler' => null,
            'curlTimeoutMilliseconds' => 1234,
        );

        foreach ($expectedValues as $name => $expected){
            self::assertTrue($rcHttp->hasProperty($name));

            $prop = $rcHttp->getProperty($name);
            $prop->setAccessible(true);
            $actual = $prop->getValue($httpClient);
            self::assertSame($expected, $actual);
        }
    }

    public function testCapture(): void
    {
        self::assertTrue(
            $this->client->capture(
                array(
                    "distinctId" => "lib-curl-capture",
                    "event" => "PHP Lib Curl'd\" Event",
                )
            )
        );
    }

    public function testIdentify(): void
    {
        self::assertTrue(
            $this->client->identify(
                array(
                    "distinctId" => "lib-curl-identify",
                    "properties" => array(
                        "loves_php" => false,
                        "type" => "consumer lib-curl test",
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
                    "alias" => "lib-curl-alias",
                    "distinctId" => "user-id",
                )
            )
        );
    }
}
