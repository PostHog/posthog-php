<?php

use App\Events\OrderCompleted;
use App\Models\DemoOrder;

return [
    'enabled' => env('POSTHOG_ENABLED', true),
    'api_key' => env('POSTHOG_API_KEY'),
    'host' => env('POSTHOG_HOST', 'https://us.i.posthog.com'),
    'secret_key' => env('POSTHOG_SECRET_KEY'),
    'release_id' => env('POSTHOG_RELEASE_ID', 'laravel-example-local'),
    'capture_exceptions' => true,
    'header_context' => true,
    'queue_context' => env('POSTHOG_QUEUE_CONTEXT', true),
    'distinct_id' => null,
    'groups' => null,
    'sdk' => [
        'disableGeoip' => true,
        'consumer' => env('POSTHOG_CONSUMER', 'lib_curl'),
        'before_send' => App\Support\RedactDemoProperties::class,
    ],
    'flag_cache' => [
        'enabled' => env('POSTHOG_FLAG_CACHE', false),
        'store' => env('CACHE_STORE', 'file'),
        'ttl' => 300,
        'refresh_interval' => 30,
    ],
    'autocapture' => [
        'requests' => [
            'enabled' => env('POSTHOG_COLLECT_REQUESTS', false),
            'exclude' => ['up', 'health', 'health/*'],
            'sample_rate' => 1.0,
        ],
        // Do not enable equivalent browser pageviews at the same time.
        'pageviews' => ['enabled' => env('POSTHOG_COLLECT_PAGEVIEWS', false)],
        'auth' => [
            'enabled' => env('POSTHOG_COLLECT_AUTH', false),
            'events' => ['registered', 'login', 'logout', 'failed', 'lockout'],
        ],
        'models' => [
            'enabled' => env('POSTHOG_COLLECT_MODELS', false),
            'allow' => [DemoOrder::class => ['amount', 'status']],
        ],
        'domain_events' => env('POSTHOG_COLLECT_DOMAIN', false) ? [
            OrderCompleted::class => ['event' => 'order_completed', 'properties' => ['amount' => 'amount']],
        ] : [],
        'jobs' => ['enabled' => env('POSTHOG_COLLECT_JOBS', false), 'exclude' => []],
        'commands' => ['enabled' => env('POSTHOG_COLLECT_COMMANDS', false), 'exclude' => []],
        'scheduler' => ['enabled' => env('POSTHOG_COLLECT_SCHEDULER', false), 'exclude' => []],
    ],
];
