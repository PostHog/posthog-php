<?php

require_once __DIR__ . "/../lib/PostHog/Client.php";

class ConsumerLibCurlTest extends PHPUnit_Framework_TestCase
{
  private $client;

  public function setUp()
  {
    date_default_timezone_set("UTC");
    $this->client = new PostHog_Client(
      "BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg",
      array(
        "consumer" => "lib_curl",
        "debug" => true,
      )
    );
  }

  public function testCapture()
  {
    $this->assertTrue($this->client->capture(array(
      "userId" => "lib-curl-capture",
      "event" => "PHP Lib Curl'd\" Event",
    )));
  }

  public function testIdentify()
  {
    $this->assertTrue($this->client->identify(array(
      "userId" => "lib-curl-identify",
      "properties" => array(
        "loves_php" => false,
        "type" => "consumer lib-curl test",
        "birthday" => time(),
      ),
    )));
  }

  public function testAlias()
  {
    $this->assertTrue($this->client->alias(array(
      "previousId" => "lib-curl-alias",
      "userId" => "user-id",
    )));
  }

  public function testRequestCompression() {
    $options = array(
      "compress_request" => true,
      "consumer"      => "lib_curl",
      "error_handler" => function ($errno, $errmsg) {
        throw new \RuntimeException($errmsg, $errno);
      },
    );

    $client = new PostHog_Client("x", $options);

    # Should error out with debug on.
    $client->capture(array("user_id" => "some-user", "event" => "Socket PHP Event"));
    $client->__destruct();
  }

  public function testLargeMessageSizeError()
  {
    $options = array(
      "debug" => true,
      "consumer" => "lib_curl",
    );

    $client = new PostHog_Client("testlargesize", $options);

    $big_property = "";

    for ($i = 0; $i < 32 * 1024; ++$i) {
      $big_property .= "a";
    }

    $this->assertFalse(
      $client->capture(
        array(
          "userId" => "some-user",
          "event" => "Super Large PHP Event",
          "properties" => array("big_property" => $big_property),
        )
      ) && $client->flush()
    );

    $client->__destruct();
  }
}
