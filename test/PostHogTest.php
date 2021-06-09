<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\PostHog;

class PostHogTest extends TestCase
{
    public function setUp(): void
    {
        date_default_timezone_set("UTC");
        PostHog::init("BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg", array("debug" => true));
    }

    public function testCapture()
    {
        $this->assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "john",
                    "event" => "Module PHP Event",
                )
            )
        );
    }

    public function testIdentify()
    {
        $this->assertTrue(
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

    public function testEmptyProperties()
    {
        $this->assertTrue(
            PostHog::identify(
                array(
                    "distinctId" => "empty-properties",
                )
            )
        );

        $this->assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "empty-properties",
                )
            )
        );
    }

    public function testEmptyArrayProperties()
    {
        $this->assertTrue(
            PostHog::identify(
                array(
                    "distinctId" => "empty-properties",
                    "properties" => array(),
                )
            )
        );

        $this->assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "empty-properties",
                    "properties" => array(),
                )
            )
        );
    }

    public function testAlias()
    {
        $this->assertTrue(
            PostHog::alias(
                array(
                    "alias" => "previous-id",
                    "distinctId" => "user-id",
                )
            )
        );
    }

    public function testTimestamps()
    {
        $this->assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "integer-timestamp",
                    "timestamp" => (int)mktime(0, 0, 0, date('n'), 1, date('Y')),
                )
            )
        );

        $this->assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "string-integer-timestamp",
                    "timestamp" => (string)mktime(0, 0, 0, date('n'), 1, date('Y')),
                )
            )
        );

        $this->assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "iso8630-timestamp",
                    "timestamp" => date(DATE_ATOM, mktime(0, 0, 0, date('n'), 1, date('Y'))),
                )
            )
        );

        $this->assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "iso8601-timestamp",
                    "timestamp" => date(DATE_ATOM, mktime(0, 0, 0, date('n'), 1, date('Y'))),
                )
            )
        );

        $this->assertTrue(
            PostHog::capture(
                array(
                    "distinctId" => "user-id",
                    "event" => "strtotime-timestamp",
                    "timestamp" => strtotime('1 week ago'),
                )
            )
        );
    }
}
