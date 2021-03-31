<?php

use PostHog\Client;

class ConsumerForkCurlTest extends PHPUnit\Framework\TestCase
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

  public function testCapture()
  {
    $this->assertTrue($this->client->capture(array(
      "distinctId" => "some-user",
      "event" => "PHP Fork Curl'd\" Event",
    )));
  }

  public function testIdentify()
  {
    $this->assertTrue($this->client->identify(array(
      "distinctId" => "user-id",
      "properties" => array(
        "loves_php" => false,
        "type" => "consumer fork-curl test",
        "birthday" => time(),
      ),
    )));
  }

  public function testAlias()
  {
    $this->assertTrue($this->client->alias(array(
      "alias" => "alias-id",
      "distinctId" => "user-id",
    )));
  }
}
