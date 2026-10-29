<?php

return [
    'requests' => [
        'enabled' => false,
        'exclude' => [
            'up', 'health', 'health/*', 'build/*', 'assets/*', 'vendor/*', 'favicon.ico', 'robots.txt',
            '*.css', '*.js', '*.map', '*.png', '*.jpg', '*.svg', '*.ico', '*.woff*',
        ],
        'sample_rate' => 1.0,
    ],
    // Server HTML pageviews can duplicate browser SDK pageviews. Enable only one source.
    'pageviews' => ['enabled' => false],
    'auth' => ['enabled' => false, 'events' => ['registered', 'login', 'logout']],
    // Exact model class => approved attribute names. Empty lists capture class metadata only.
    'models' => ['enabled' => false, 'allow' => []],
    // Exact event class => ['event' => 'name', 'properties' => ['output' => 'public.property.path']].
    'domain_events' => [],
    'jobs' => ['enabled' => false, 'exclude' => []],
    'commands' => ['enabled' => false, 'exclude' => []],
    'scheduler' => ['enabled' => false, 'exclude' => []],
    'blade' => ['enabled' => false],
];
