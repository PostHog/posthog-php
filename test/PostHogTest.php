<?php

require_once __DIR__ . "/../lib/PostHog.php";

class PostHogTest extends PHPUnit_Framework_TestCase
{
  public function setUp()
  {
    date_default_timezone_set("UTC");
    PostHog::init("BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg", array("debug" => true));
  }

  public function testCapture()
  {
    $this->assertTrue(PostHog::capture(array(
      "userId" => "john",
      "event" => "Module PHP Event",
    )));
  }

  public function testIdentify()
  {
    $this->assertTrue(PostHog::identify(array(
      "userId" => "doe",
      "properties" => array(
        "loves_php" => false,
        "birthday" => time(),
      ),
    )));
  }

  public function testEmptyProperties()
  {
    $this->assertTrue(PostHog::identify(array(
      "userId" => "empty-properties",
    )));

    $this->assertTrue(PostHog::capture(array(
      "userId" => "user-id",
      "event" => "empty-properties",
    )));
  }

  public function testEmptyArrayProperties()
  {
    $this->assertTrue(PostHog::identify(array(
      "userId" => "empty-properties",
      "properties" => array(),
    )));

    $this->assertTrue(PostHog::capture(array(
      "userId" => "user-id",
      "event" => "empty-properties",
      "properties" => array(),
    )));
  }

  public function testAlias()
  {
    $this->assertTrue(PostHog::alias(array(
      "previousId" => "previous-id",
      "userId" => "user-id",
    )));
  }

  public function testContextEmpty()
  {
    $this->assertTrue(PostHog::capture(array(
      "userId" => "user-id",
      "event" => "Context Test",
      "context" => array(),
    )));
  }

  public function testContextCustom()
  {
    $this->assertTrue(PostHog::capture(array(
      "userId" => "user-id",
      "event" => "Context Test",
      "context" => array(
        "active" => false,
      ),
    )));
  }

  public function testTimestamps()
  {
    $this->assertTrue(PostHog::capture(array(
      "userId" => "user-id",
      "event" => "integer-timestamp",
      "timestamp" => (int) mktime(0, 0, 0, date('n'), 1, date('Y')),
    )));

    $this->assertTrue(PostHog::capture(array(
      "userId" => "user-id",
      "event" => "string-integer-timestamp",
      "timestamp" => (string) mktime(0, 0, 0, date('n'), 1, date('Y')),
    )));

    $this->assertTrue(PostHog::capture(array(
      "userId" => "user-id",
      "event" => "iso8630-timestamp",
      "timestamp" => date(DATE_ATOM, mktime(0, 0, 0, date('n'), 1, date('Y'))),
    )));

    $this->assertTrue(PostHog::capture(array(
      "userId" => "user-id",
      "event" => "iso8601-timestamp",
      "timestamp" => date(DATE_ATOM, mktime(0, 0, 0, date('n'), 1, date('Y'))),
    )));

    $this->assertTrue(PostHog::capture(array(
      "userId" => "user-id",
      "event" => "strtotime-timestamp",
      "timestamp" => strtotime('1 week ago'),
    )));

    $this->assertTrue(PostHog::capture(array(
      "userId" => "user-id",
      "event" => "microtime-timestamp",
      "timestamp" => microtime(true),
    )));

    $this->assertTrue(PostHog::capture(array(
      "userId" => "user-id",
      "event" => "invalid-float-timestamp",
      "timestamp" => ((string) mktime(0, 0, 0, date('n'), 1, date('Y'))) . '.',
    )));
  }
}
