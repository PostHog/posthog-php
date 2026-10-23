<?php

require $argv[1];

$shape = $argv[2];
$count = (int) $argv[3];
$mode = $argv[4];
$includeNonFinite = ($argv[5] ?? '0') === '1';
$nativeType = $argv[6] ?? '';
$items = [];
switch ($shape) {
    case 'empty-arrays':
        $items = array_fill(0, $count, []);
        break;
    case 'shared-scalar-arrays':
        $items = array_fill(0, $count, [0]);
        break;
    case 'distinct-scalar-arrays':
        for ($i = 0; $i < $count; ++$i) {
            $items[] = [$i % 10];
        }
        break;
    case 'shared-empty-object':
        $items = array_fill(0, $count, new stdClass());
        break;
    case 'distinct-empty-objects':
        for ($i = 0; $i < $count; ++$i) {
            $items[] = new stdClass();
        }
        break;
    case 'declared-scalar-objects':
        $prototype = new class {
            public int $n = 1;
        };
        for ($i = 0; $i < $count; ++$i) {
            $items[] = clone $prototype;
        }
        break;
    case 'distinct-scalar-objects':
        for ($i = 0; $i < $count; ++$i) {
            $items[] = (object) ['n' => $i % 10];
        }
        break;
}
$properties = ['items' => $items];
if ($includeNonFinite) {
    $properties['numbers'] = [NAN, INF, -INF];
    $properties['finite'] = 2.5;
    $properties['literal'] = 'NaN';
}
if ($nativeType !== '') {
    $native = match ($nativeType) {
        'DateTime' => new DateTime('2024-01-01T00:00:00Z'),
        'DateTimeImmutable' => new DateTimeImmutable('2024-01-01T00:00:00Z'),
        'DateTimeZone' => new DateTimeZone('UTC'),
        'ArrayObject', 'ArrayObject-properties' => new ArrayObject(['stored' => 7]),
        'ArrayIterator', 'ArrayIterator-properties' => new ArrayIterator(['stored' => 7]),
    };
    if (str_ends_with($nativeType, '-properties')) {
        $native->setFlags(ArrayObject::STD_PROP_LIST);
        $native->visible = 7;
    }
    $properties['native'] = $native;
}
$batch = [['event' => 'memory', 'distinct_id' => 'u', 'properties' => $properties]];
echo "prepared\n";
if ($mode === 'native') {
    $expectedBatch = $batch;
    if ($includeNonFinite) {
        $expectedBatch[0]['properties']['numbers'] = [null, null, null];
    }
    $encoded = json_encode(['batch' => $expectedBatch, 'api_key' => 'test-key']);
} else {
    $consumer = new PostHog\Test\QueueConsumerTestConsumer([]);
    $encoded = $consumer->encodedBatchPayload($batch);
}
if ($encoded === false) {
    exit(1);
}
if ($includeNonFinite) {
    if (
        !is_nan($batch[0]['properties']['numbers'][0])
        || $batch[0]['properties']['numbers'][1] !== INF
        || $batch[0]['properties']['numbers'][2] !== -INF
    ) {
        exit(2);
    }
}
echo json_encode([
    'bytes' => strlen($encoded),
    'sha256' => hash('sha256', $encoded),
], JSON_THROW_ON_ERROR), "\n";
