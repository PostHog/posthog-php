<?php

require_once __DIR__ . "/../lib/PostHog/Client.php";

class ConsumerForkCurlTest extends PHPUnit_Framework_TestCase
{
  private $client;

  public function setUp()
  {
    date_default_timezone_set("UTC");
    $this->client = new PostHog_Client(
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
      "userId" => "some-user",
      "event" => "PHP Fork Curl'd\" Event",
    )));
  }

  public function testIdentify()
  {
    $this->assertTrue($this->client->identify(array(
      "userId" => "user-id",
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
      "previousId" => "previous-id",
      "userId" => "user-id",
    )));
  }

  public function testRequestCompression() {
    $options = array(
      "compress_request" => true,
      "consumer" => "fork_curl",
      "debug" => true,
    );

    // Create client and send Capture message
    $client = new PostHog_Client("OnMMoZ6YVozrgSBeZ9FpkC0ixH0ycYZn", $options);
    $result = $client->capture(array(
      "userId" => "some-user",
      "event" => "PHP Fork Curl'd\" Event with compression",
    ));
    $client->__destruct();

    $this->assertTrue($result);
  }
}
