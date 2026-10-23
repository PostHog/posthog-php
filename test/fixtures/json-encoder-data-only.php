<?php

require $argv[1];

$number = $argv[3] === 'native' ? null : NAN;
$properties = $argv[2] === 'dense'
    ? ['items' => array_fill(0, 4000, ['number' => $number])]
    : ['number' => $number, 'text' => str_repeat("\0", 128 * 1024)];
$batch = [['event' => 'eligible-memory', 'properties' => $properties]];
if ($argv[3] === 'native') {
    $encoded = json_encode(['batch' => $batch, 'api_key' => 'test-key']);
} else {
    $consumer = new PostHog\Test\QueueConsumerTestConsumer([]);
    $encoded = $consumer->encodedBatchPayload($batch);
    if ($argv[2] === 'dense') {
        if (!is_nan($properties['items'][0]['number']) || !is_nan($properties['items'][3999]['number'])) {
            exit(2);
        }
    } elseif (!is_nan($properties['number'])) {
        exit(2);
    }
}
if ($encoded === false) {
    exit(1);
}
echo json_encode(['bytes' => strlen($encoded), 'sha256' => hash('sha256', $encoded)]);
