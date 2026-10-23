<?php

require $argv[1];

$items = [];
if ($argv[2] === 'siblings') {
    $items = array_fill(0, 2000000, []);
} else {
    $numbers = array_fill(0, 60000, 1);
    for ($i = 0; $i < 60000; ++$i) {
        $items[$i] = &$numbers[$i];
    }
}
$batch = [['event' => 'scale', 'properties' => ['items' => $items]]];
if ($argv[3] === 'native') {
    $encoded = json_encode(['batch' => $batch, 'api_key' => 'test-key']);
} else {
    $encoded = PostHog\Internal\JsonEncoder::encode(['batch' => $batch, 'api_key' => 'test-key']);
    $consumer = new PostHog\Test\QueueConsumerTestConsumer([]);
    $payload = $consumer->encodedBatchPayload($batch);
    $expectedPayload = $argv[2] === 'siblings' ? false : $encoded;
    if ($payload !== $expectedPayload || json_last_error() !== JSON_ERROR_NONE) {
        exit(3);
    }
}
if ($encoded === false) {
    exit(1);
}
if ($argv[2] === 'references' && ($numbers[0] !== 1 || $numbers[59999] !== 1)) {
    exit(2);
}
echo json_encode(['bytes' => strlen($encoded), 'sha256' => hash('sha256', $encoded)]);
