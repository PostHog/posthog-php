<?php

return [
    'enabled' => env('POSTHOG_ENABLED', true),
    'api_key' => env('POSTHOG_API_KEY'),
    'host' => env('POSTHOG_HOST', 'https://us.i.posthog.com'),
    'secret_key' => env('POSTHOG_SECRET_KEY'),
    'release_id' => env('POSTHOG_RELEASE_ID'),
    'capture_exceptions' => true,
    'header_context' => true,
    // Invokable container class; receives the application and returns a distinct ID or null.
    'distinct_id' => null,
    // Invokable container class; receives the application and returns group identifiers.
    'groups' => null,
    'sdk' => [
        'disableGeoip' => true,
        // before_send, error_handler and flag_definition_cache_provider accept container class names.
    ],
    'flag_cache' => [
        'enabled' => false,
        'store' => null,
        'ttl' => 300,
        'refresh_interval' => 30,
    ],
];
