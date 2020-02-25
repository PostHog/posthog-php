<?php

require_once __DIR__ . "/../lib/PostHog/Client.php";

class ConsumerSocketTest extends PHPUnit_Framework_TestCase
{
  private $client;

  public function setUp()
  {
    date_default_timezone_set("UTC");
    $this->client = new PostHog_Client(
      "BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg",
      array("consumer" => "socket")
    );
  }

  public function testCapture()
  {
    $this->assertTrue($this->client->capture(array(
      "distinctId" => "some-user",
      "event" => "Socket PHP Event",
    )));
  }

  public function testIdentify()
  {
    $this->assertTrue($this->client->identify(array(
      "distinctId" => "Calvin",
      "properties" => array(
        "loves_php" => false,
        "birthday" => time(),
      ),
    )));
  }

  public function testAlias()
  {
    $this->assertTrue($this->client->alias(array(
      "previousId" => "some-socket",
      "distinctId" => "new-socket",
    )));
  }

  public function testShortTimeout()
  {
    $client = new PostHog_Client(
      "BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg",
      array(
        "timeout" => 0.01,
        "consumer" => "socket",
      )
    );

    $this->assertTrue($client->capture(array(
      "distinctId" => "some-user",
      "event" => "Socket PHP Event",
    )));

    $this->assertTrue($client->identify(array(
      "distinctId" => "some-user",
      "properties" => array(),
    )));

    $client->__destruct();
  }

  public function testProductionProblems()
  {
    $client = new PostHog_Client("x",
      array(
        "consumer" => "socket",
        "error_handler" => function () {
          throw new Exception("Was called");
        },
      )
    );

    // Shouldn't error out without debug on.
    $client->capture(array("user_id" => "some-user", "event" => "Production Problems"));
    $client->__destruct();
  }

  public function testDebugProblems()
  {
    $options = array(
      "debug" => true,
      "consumer" => "socket",
      "error_handler" => function ($errno, $errmsg) {
        if (400 != $errno) {
          throw new Exception("Response is not 400");
        }
      },
    );

    $client = new PostHog_Client("x", $options);

    // Should error out with debug on.
    $client->capture(array("user_id" => "some-user", "event" => "Socket PHP Event"));
    $client->__destruct();
  }

  public function testLargeMessage()
  {
    $options = array(
      "debug" => true,
      "consumer" => "socket",
    );

    $client = new PostHog_Client("testsecret", $options);

    $big_property = "";

    for ($i = 0; $i < 10000; ++$i) {
      $big_property .= "a";
    }

    $this->assertTrue($client->capture(array(
      "distinctId" => "some-user",
      "event" => "Super Large PHP Event",
      "properties" => array("big_property" => $big_property),
    )));

    $client->__destruct();
  }

  public function testLargeMessageSizeError()
  {
    $options = array(
      "debug" => true,
      "consumer" => "socket",
    );

    $client = new PostHog_Client("testlargesize", $options);

    $big_property = "";

    for ($i = 0; $i < 32 * 1024; ++$i) {
      $big_property .= "a";
    }

    $this->assertFalse(
      $client->capture(
        array(
          "distinctId" => "some-user",
          "event" => "Super Large PHP Event",
          "properties" => array("big_property" => $big_property),
        )
      ) && $client->flush()
    );

    $client->__destruct();
  }

  /**
   * @expectedException \RuntimeException
   */
  public function testConnectionError()
  {
    $client = new PostHog_Client("x", array(
      "consumer" => "socket",
      "host" => "t.posthog.comcomcom",
      "error_handler" => function ($errno, $errmsg) {
        throw new \RuntimeException($errmsg, $errno);
      },
    ));

    $client->capture(array("user_id" => "some-user", "event" => "Event"));
    $client->__destruct();
  }

  public function testRequestCompression() {
    $options = array(
      "compress_request" => true,
      "consumer"      => "socket",
      "error_handler" => function ($errno, $errmsg) {
        throw new \RuntimeException($errmsg, $errno);
      },
    );

    $client = new PostHog_Client("x", $options);

    # Should error out with debug on.
    $client->capture(array("user_id" => "some-user", "event" => "Socket PHP Event"));
    $client->__destruct();
  }
}
