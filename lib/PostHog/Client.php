<?php

require_once(__DIR__ . '/Consumer.php');
require_once(__DIR__ . '/QueueConsumer.php');
require_once(__DIR__ . '/Consumer/File.php');
require_once(__DIR__ . '/Consumer/ForkCurl.php');
require_once(__DIR__ . '/Consumer/LibCurl.php');
require_once(__DIR__ . '/Consumer/Socket.php');
require_once(__DIR__ . '/Version.php');

class PostHog_Client {
  protected $consumer;

  /**
   * Create a new posthog object with your app's API key
   * key
   *
   * @param string $apiKey
   * @param array  $options array of consumer options [optional]
   * @param string Consumer constructor to use, libcurl by default.
   *
   */
  public function __construct($apiKey, $options = array()) {
    $consumers = array(
      "socket"     => "PostHog_Consumer_Socket",
      "file"       => "PostHog_Consumer_File",
      "fork_curl"  => "PostHog_Consumer_ForkCurl",
      "lib_curl"   => "PostHog_Consumer_LibCurl"
    );

    // Use our socket libcurl by default
    $consumer_type = isset($options["consumer"]) ? $options["consumer"] :
                                                   "lib_curl";

    $Consumer = $consumers[$consumer_type];

    $this->consumer = new $Consumer($apiKey, $options);
  }

  public function __destruct() {
    $this->consumer->__destruct();
  }

  /**
   * Captures a user action
   *
   * @param  array $message
   * @return [boolean] whether the capture call succeeded
   */
  public function capture(array $message) {
    $message = $this->message($message);
    $message["type"] = "capture";

    return $this->consumer->capture($message);
  }

  /**
   * Tags properties about the user.
   *
   * @param  [array] $message
   * @return [boolean] whether the identify call succeeded
   */
  public function identify(array $message) {
    $message['$set'] = $message['properties'];

    $message = $this->message($message);
    $message["type"] = "identify";
    $message["event"] = '$identify';

    return $this->consumer->identify($message);
  }

  /**
   * Aliases from one user id to another
   *
   * @param  array $message
   * @return boolean whether the alias call succeeded
   */
  public function alias(array $message) {
    $message = $this->message($message);
    $message["type"] = "alias";
    $message["event"] = '$create_alias';

    $message['properties']['distinct_id'] = $message['distinct_id'];
    $message['properties']['alias'] = $message['alias'];

    $message['distinct_id'] = null;
    unset($message['alias']);

    return $this->consumer->alias($message);
  }

  /**
   * Queue a raw (prepared) message
   *
   * @param  [array] $message
   * @return [boolean] whether the identify call succeeded
   */
  public function raw(array $message) {
    return $this->consumer->enqueue($message);
  }

  /**
   * Flush any async consumers
   * @return boolean true if flushed successfully
   */
  public function flush() {
    if (method_exists($this->consumer, 'flush')) {
      return $this->consumer->flush();
    }

    return true;
  }

  /**
   * Formats a timestamp by making sure it is set
   * and converting it to iso8601.
   *
   * The timestamp can be time in seconds `time()` or `microseconds(true)`.
   * any other input is considered an error and the method will return a new date.
   *
   * Note: php's date() "u" format (for microseconds) has a bug in it
   * it always shows `.000` for microseconds since `date()` only accepts
   * ints, so we have to construct the date ourselves if microtime is passed.
   *
   * @param  ts $timestamp - time in seconds (time())
   */
  private function formatTime($ts) {
    // time()
    if (null == $ts || !$ts) {
      $ts = time();
    }
    if (false !== filter_var($ts, FILTER_VALIDATE_INT)) {
      return date("c", (int) $ts);
    }

    // anything else try to strtotime the date.
    if (false === filter_var($ts, FILTER_VALIDATE_FLOAT)) {
      if (is_string($ts)) {
        return date("c", strtotime($ts));
      }

      return date("c");
    }

    // fix for floatval casting in send.php
    $parts = explode(".", (string)$ts);
    if (!isset($parts[1])) {
      return date("c", (int)$parts[0]);
    }

    // microtime(true)
    $sec = (int)$parts[0];
    $usec = (int)$parts[1];
    $fmt = sprintf("Y-m-d\\TH:i:s%sP", $usec);

    return date($fmt, (int)$sec);
  }

  /**
   * Add common fields to the given `message`
   *
   * @param array $msg
   * @param string $def
   * @return array
   */

  private function message($msg){
    global $POSTHOG_VERSION;

    if (!isset($msg["properties"])) {
      $msg["properties"] = array();
    }

    $msg["library"] = 'posthog-php';
    $msg["library_version"] = $POSTHOG_VERSION;
    $msg["library_consumer"] = $this->consumer->getConsumer();

    $msg["properties"]['$lib'] = 'posthog-php';
    $msg["properties"]['$lib_version'] = $POSTHOG_VERSION;
    $msg["properties"]['$lib_consumer'] = $this->consumer->getConsumer();

    if (isset($msg["distinctId"])) {
      $msg["distinct_id"] = $msg["distinctId"];
      unset($msg["distinctId"]);
    }

    if (!isset($msg["timestamp"])) {
      $msg["timestamp"] = null;
    }
    $msg["timestamp"] = $this->formatTime($msg["timestamp"]);

    return $msg;
  }
}
