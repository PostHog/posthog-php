<?php

require $argv[1];

$shape = $argv[2];
$mode = $argv[3];
$count = $shape === 'root' ? 1000000 : 1048576;
$items = array_fill(0, $count, 0);
$index = $shape === 'high-key' ? $count - 1 : 0;
if ($shape === 'high-key') {
    for ($i = 0; $i < $index; ++$i) {
        unset($items[$i]);
    }
} else {
    for ($i = $count - 1; $i > 0; --$i) {
        unset($items[$i]);
    }
}
$number = $shape === 'siblings' ? INF : NAN;
$items[$index] = $mode === 'native-null' ? null : $number;
$input = $shape === 'siblings' ? ['items' => array_fill(0, 16, $items)] : $items;
$encoded = $mode === 'sdk' ? PostHog\Internal\JsonEncoder::encode($input) : json_encode($input);
if ($mode === 'native-error') {
    if ($encoded !== false || json_last_error() !== JSON_ERROR_INF_OR_NAN) {
        exit(1);
    }
    echo json_encode(['error' => JSON_ERROR_INF_OR_NAN]);
    exit(0);
}
if ($encoded === false) {
    exit(1);
}
if ($mode === 'sdk') {
    if (count($items) !== 1 || array_key_first($items) !== $index) {
        exit(2);
    }
    if (is_nan($number) ? !is_nan($items[$index]) : $items[$index] !== $number) {
        exit(2);
    }
}
echo json_encode(['bytes' => strlen($encoded), 'sha256' => hash('sha256', $encoded)]);
