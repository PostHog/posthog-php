<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * require client
 */

use PostHog\PostHog;

/**
 * Args
 */

if (in_array('--help', $argv, true)) {
  print("Usage: php send.php --apiKey KEY --file FILE [--host HOST]\n");
  exit(0);
}

$args = parse($argv);

/**
 * Make sure both are set
 */

if (!isset($args["apiKey"])) die("--apiKey must be given");
if (!isset($args["file"])) die("--file must be given");

$file = $args["file"];
if ($file[0] != '/') $file = __DIR__ . "/" . $file;

/**
 * Rename the file so we don't write the same calls
 * multiple times
 */

$dir = dirname($file);
$old = $file;
$file = $dir . '/posthog-' . rand() . '.log';

if(!file_exists($old)) {
  print("file: $old does not exist");
  exit(0);
}

if (!rename($old, $file)) {
  print("error renaming from $old to $file\n");
  exit(1);
}

/**
 * File contents.
 */

$contents = file_get_contents($file);
$lines = explode("\n", $contents);

/**
 * Initialize the client.
 */

$options = array(
  "debug" => true,
  "error_handler" => function($code, $msg){
    print("$code: $msg\n");
    exit(1);
  }
);
if (isset($args["host"])) $options["host"] = $args["host"];
PostHog::init($args["apiKey"], $options);

/**
 * Payloads
 */

$total = 0;
$successful = 0;
foreach ($lines as $line) {
  if (!trim($line)) continue;
  // Keep the public array envelope without turning nested JSON objects into lists.
  $payload = \PostHog\EventSerializer::decode($line, $decodeError);
  if ($decodeError !== null) {
    print("Failed to decode event payload: $decodeError\n");
    exit(1);
  }
  $ret = call_user_func_array(array("PostHog\\PostHog", "raw"), array($payload));
  if ($ret) $successful++;
  $total++;
  if ($total % 100 === 0) PostHog::flush();
}

PostHog::flush();
unlink($file);

/**
 * Sent
 */

print("sent $successful from $total requests successfully");
exit(0);

/**
 * Parse arguments
 */

function parse($argv){
  $ret = array();

  for ($i = 0; $i < count($argv); ++$i) {
    $arg = $argv[$i];
    if ('--' != substr($arg, 0, 2)) continue;
    $ret[substr($arg, 2, strlen($arg))] = trim($argv[++$i]);
  }

  return $ret;
}
