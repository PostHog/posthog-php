<?php

namespace PostHog;

use PostHog\Client;

class PostHog {
  public const VERSION = '1.0.0';

  private static $client;

  /**
   * Initializes the default client to use. Uses the libcurl consumer by default.
   * @param  string $apiKey   your project's API key
   * @param  array  $options  passed straight to the client
   */
  public static function init($apiKey, $options = array()) {
    self::assert($apiKey, "PostHog::init() requires an apiKey");
    self::$client = new Client($apiKey, $options);
  }

  /**
   * Captures a user action
   *
   * @param  array $message
   * @return boolean whether the capture call succeeded
   */
  public static function capture(array $message) {
    self::checkClient();
    $event = !empty($message["event"]);
    self::assert($event, "PostHog::capture() expects an event");
    self::validate($message, "capture");

    return self::$client->capture($message);
  }

  /**
   * Tags properties about the user.
   *
   * @param  array  $message
   * @return boolean whether the identify call succeeded
   */
  public static function identify(array $message) {
    self::checkClient();
    $message["type"] = "identify";
    self::validate($message, "identify");

    return self::$client->identify($message);
  }

  /**
   * Aliases the distinct id from a temporary id to a permanent one
   *
   * @param  array $message      distinct id to alias from
   * @return boolean whether the alias call succeeded
   */
  public static function alias(array $message) {
    self::checkClient();
    $alias = !empty($message["alias"]);
    self::assert($alias, "PostHog::alias() requires an alias");
    self::validate($message, "alias");

    return self::$client->alias($message);
  }

  /**
   * Send a raw (prepared) message
   *
   * @param  array $message      distinct id to alias from
   * @return boolean whether the alias call succeeded
   */
  public static function raw(array $message) {
    return self::$client->raw($message);
  }


  /**
   * Validate common properties.
   *
   * @param array $msg
   * @param string $type
   */
  public static function validate($msg, $type){
    $distinctId = !empty($msg["distinctId"]);
    self::assert($distinctId, "PostHog::${type}() requires distinctId");
  }

  /**
   * Flush the client
   */

  public static function flush(){
    self::checkClient();

    return self::$client->flush();
  }

  /**
   * Check the client.
   *
   * @throws Exception
   */
  private static function checkClient(){
    if (null != self::$client) {
      return;
    }

    throw new Exception("PostHog::init() must be called before any other capturing method.");
  }

  /**
   * Assert `value` or throw.
   *
   * @param array $value
   * @param string $msg
   * @throws Exception
   */
  private static function assert($value, $msg) {
    if (!$value) {
      throw new Exception($msg);
    }
  }
}

if (!function_exists('json_encode')) {
  throw new Exception('PostHog needs the JSON PHP extension.');
}
