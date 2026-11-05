<?php

declare(strict_types=1);

return [
    // Enable only after VPS acceptance has verified storage, retention and recovery guarantees.
    'enabled' => env('ACCOUNT_DELETION_ENABLED', false),
    'registry' => [
        'driver' => env('ACCOUNT_DELETION_REGISTRY_DRIVER', 'unavailable'),
        'namespace' => env('ACCOUNT_DELETION_REGISTRY_NAMESPACE'),
    ],
    // All application Redis queue names; additional queues must be listed before use.
    'redis_queues' => array_values(array_filter(explode(',', env('ACCOUNT_DELETION_REDIS_QUEUES', env('REDIS_QUEUE', 'default'))))),
];
