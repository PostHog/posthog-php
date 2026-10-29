<?php

return [
    'default' => env('QUEUE_CONNECTION', 'sync'),
    'connections' => [
        'sync' => ['driver' => 'sync'],
        'database' => [
            'driver' => 'database', 'connection' => null, 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 90, 'after_commit' => true,
        ],
    ],
    'failed' => ['driver' => 'database-uuids', 'database' => env('DB_CONNECTION', 'sqlite'), 'table' => 'failed_jobs'],
];
