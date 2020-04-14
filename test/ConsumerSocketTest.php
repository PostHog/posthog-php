<?php

require_once __DIR__ . "/../lib/PostHog/Client.php";

class ConsumerSocketTest extends PHPUnit\Framework\TestCase
{
  private $client;

  public function setUp(): void
  {
    date_default_timezone_set("UTC");
  }

  public function testCapture()
  {
    $client = new PostHog_Client(
      "BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg",
      array(
        "consumer" => "socket",
      )
    );
    $this->assertTrue($client->capture(array(
      "distinctId" => "some-user",
      "event" => "Socket PHP Event",
    )));
    $client->__destruct();
  }

  public function testIdentify()
  {
    $client = new PostHog_Client(
      "BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg",
      array(
        "consumer" => "socket",
      )
    );
    $this->assertTrue($client->identify(array(
      "distinctId" => "Calvin",
      "properties" => array(
        "loves_php" => false,
        "birthday" => time(),
      ),
    )));
    $client->__destruct();
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
    $this->assertTrue(true);
  }


  public function testLargeMessage()
  {
    $options = array(
      "debug" => true,
      "consumer" => "socket",
    );

    $client = new PostHog_Client("BrpS4SctoaCCsyjlnlun3OzyNJAafdlv__jUWaaJWXg", $options);

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

  public function testConnectionError()
  {
    $this->expectException('RuntimeException');
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
}
