<?php

namespace PostHog\Test;

use Exception;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\PostHog;

const FAKE_API_KEY = "random_key";

class PostHogTest extends TestCase
{
    public function setUp(): void
    {
        date_default_timezone_set("UTC");
        $this->http_client = new MockedHttpClient("app.posthog.com");
        $this->client = new Client(
            FAKE_API_KEY,
            [
                "debug" => true,
            ],
            $this->http_client
        );
        PostHog::init(null, null, $this->client);
    }

    public function testInitWithParamApiKey(): void
    {
        $this->expectNotToPerformAssertions();
        PostHog::init("BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg", array("debug" => true));
    }

    public function testInitWithEnvApiKey(): void
    {
        $this->expectNotToPerformAssertions();
        putenv(PostHog::ENV_API_KEY . "=BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg");
        PostHog::init(null, array("debug" => true));

        // Clear the environment variable
        putenv(PostHog::ENV_API_KEY);
    }

    public function testInitThrowsExceptionWithNoApiKey(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("PostHog::init() requires an apiKey");
        PostHog::init(null);
    }

    public function testCapture(): void
    {
        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "john",
                    "event" => "Module PHP Event",
                )
            )
        );
    }

    public function testCaptureWithSendFeatureFlagsOption(): void
    {
        $this->assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "john",
                    "event" => "Module PHP Event",
                    "sendFeatureFlags" => true
                )
            )
        );
        $this->assertEquals(
            $this->http_client->calls,
            array(
                0 => array(
                    "path" => "/api/feature_flag/local_evaluation",
                    "payload" => sprintf('{"api_key":"%s"}', FAKE_API_KEY),
                ),
                1 => array(
                    "path" => "/decide/?v=2",
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"john"}', FAKE_API_KEY),
                ),
            )
        );
    }

    public function testIdentify(): void
    {
        self::assertTrue(
            PostHog::identify(
                array(
                    "distinctId" => "doe",
                    "properties" => array(
                        "loves_php" => false,
                        "birthday" => time(),
                    ),
                )
            )
        );
    }

    public function testIsFeatureEnabled()
    {
        $this->assertFalse(PostHog::isFeatureEnabled('having_fun', 'user-id'));
        $this->assertEquals(
            $this->http_client->calls,
            array(
                0 => array(
                    "path" => "/api/feature_flag/local_evaluation",
                    "payload" => sprintf('{"api_key":"%s"}', FAKE_API_KEY),
                ),
                1 => array(
                    "path" => "/decide/?v=2",
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"user-id"}', FAKE_API_KEY),
                ),
            )
        );
    }

    public function testIsFeatureEnabledGroups()
    {
        $this->assertFalse(PostHog::isFeatureEnabled('having_fun', 'user-id', false, array("company" => "id:5")));

        $this->assertEquals(
            $this->http_client->calls,
            array(
                0 => array(
                    "path" => "/api/feature_flag/local_evaluation",
                    "payload" => sprintf('{"api_key":"%s"}', FAKE_API_KEY),
                ),
                1 => array(
                    "path" => "/decide/?v=2",
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"user-id","groups":{"company":"id:5"}}', FAKE_API_KEY),
                ),
            )
        );
    }

    public function testGetFeatureFlag()
    {
        $this->assertEquals("variant-value", PostHog::getFeatureFlag('multivariate-test', 'user-id'));
        $this->assertEquals(
            $this->http_client->calls,
            array(
                0 => array(
                    "path" => "/api/feature_flag/local_evaluation",
                    "payload" => sprintf('{"api_key":"%s"}', FAKE_API_KEY),
                ),
                1 => array(
                    "path" => "/decide/?v=2",
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"user-id"}', FAKE_API_KEY),
                ),
            )
        );
    }

    public function testGetFeatureFlagDefault()
    {
        $this->assertTrue(PostHog::getFeatureFlag('blah', 'user-id', true));
    }

    public function testGetFeatureFlagGroups()
    {
        $this->assertEquals("variant-value", PostHog::getFeatureFlag('multivariate-test', 'user-id', false, array("company" => "id:5")));

        $this->assertEquals(
            $this->http_client->calls,
            array(
                0 => array(
                    "path" => "/api/feature_flag/local_evaluation",
                    "payload" => sprintf('{"api_key":"%s"}', FAKE_API_KEY),
                ),
                1 => array(
                    "path" => "/decide/?v=2",
                    "payload" => sprintf('{"api_key":"%s","distinct_id":"user-id","groups":{"company":"id:5"}}', FAKE_API_KEY),
                ),
            )
        );
    }

    public function testfetchFeatureVariants()
    {
        $this->assertIsArray(PostHog::fetchFeatureVariants('user-id'));
    }

    public function testEmptyProperties(): void
    {
        self::assertTrue(
            PostHog::identify(
                array(
                    "distinctId" => "empty-properties",
                )
            )
        );

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "empty-properties",
                )
            )
        );
    }

    public function testEmptyArrayProperties(): void
    {
        self::assertTrue(
            PostHog::identify(
                array(
                    "distinctId" => "empty-properties",
                    "properties" => array(),
                )
            )
        );

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "empty-properties",
                    "properties" => array(),
                )
            )
        );
    }

    public function testAlias(): void
    {
        self::assertTrue(
            PostHog::alias(
                array(
                    "alias" => "previous-id",
                    "distinctId" => "user-id",
                )
            )
        );
    }

    public function testTimestamps(): void
    {
        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "integer-timestamp",
                    "timestamp" => (int)mktime(0, 0, 0, date('n'), 1, date('Y')),
                )
            )
        );

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "string-integer-timestamp",
                    "timestamp" => (string)mktime(0, 0, 0, date('n'), 1, date('Y')),
                )
            )
        );

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "iso8630-timestamp",
                    "timestamp" => date(DATE_ATOM, mktime(0, 0, 0, date('n'), 1, date('Y'))),
                )
            )
        );

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "iso8601-timestamp",
                    "timestamp" => date(DATE_ATOM, mktime(0, 0, 0, date('n'), 1, date('Y'))),
                )
            )
        );

        self::assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "strtotime-timestamp",
                    "timestamp" => strtotime('1 week ago'),
                )
            )
        );
    }

    public function testGroupIdentify(): void
    {
        self::assertTrue(
            PostHog::groupIdentify(
                array(
                    "groupType" => "company",
                    "groupKey" => "id:5",
                    "properties" => array(
                        "foo" => "bar"
                    )
                )
            )
        );

        self::assertTrue(
            PostHog::groupIdentify(
                array(
                    "groupType" => "company",
                    "groupKey" => "id:5",
                )
            )
        );
    }

    public function testGroupIdentifyValidation(): void
    {
        try {
            Posthog::groupIdentify(array());
        } catch (Exception $e) {
            $this->assertEquals("PostHog::groupIdentify() expects a groupType", $e->getMessage());
        }
    }
}
