<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

foreach (['lib_curl', 'fork_curl', 'socket'] as $consumer) {
    foreach ([1, 100] as $size) {
        // ForkCurl gzip owns a hardcoded /tmp file: deliberately do not exercise it here.
        foreach (($consumer === 'fork_curl' ? [false] : [false, true]) as $gzip) {
            $client = new \PostHog\Client('fake-key', [
                'host' => getenv('POSTHOG_TEST_HOST'),
                'consumer' => $consumer,
                'batch_size' => $size,
                'debug' => true,
                'compress_request' => $gzip,
                'maximum_backoff_duration' => 0,
                'timeout' => 2,
                'before_send' => static function ($event) {
                    $event['properties']['hookNull'] = null;
                    $event['properties']['hookItems'] = [null, ['drop' => null]];
                    return $event;
                },
            ]);
            $properties = ['test' => null, 'nested' => ['drop' => null], 'items' => [null, ['drop' => null]]];
            $client->capture(['event' => "$consumer-$size-$gzip", 'distinctId' => 'user', 'properties' => $properties]);
            $client->captureException('test exception', 'user', $properties);
            if (!$client->flush()) {
                throw new \RuntimeException('Loopback flush failed');
            }
        }
    }
}
