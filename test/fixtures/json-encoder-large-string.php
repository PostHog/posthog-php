<?php

require $argv[1];

$batch = [['event' => 'large-string', 'properties' => ['value' => str_repeat('A', 12 * 1024 * 1024)]]];
$payload = ['batch' => $batch, 'api_key' => 'test-key'];
$encoded = $argv[2] === 'native' ? json_encode($payload) : PostHog\JsonEncoder::encode($payload);
if ($encoded === false) {
    exit(1);
}
$summary = ['bytes' => strlen($encoded), 'sha256' => hash('sha256', $encoded)];
unset($encoded);
if ($argv[2] === 'sdk') {
    $consumer = new PostHog\Test\QueueConsumerTestConsumer([]);
    if ($consumer->encodedBatchPayload($batch) !== false || json_last_error() !== JSON_ERROR_NONE) {
        exit(2);
    }
}
echo json_encode($summary);
