<?php

declare(strict_types=1);

return [
    'base_path' => __DIR__ . '/../storage/data',
    'rotation' => [
        'strategy' => 'daily',  // daily, size, both
        'max_size' => 1048576,  // 1MB
        'retention_days' => 7,
        'compress_after_days' => 2,
    ],
    'locking' => [
        'enabled' => true,
        'timeout' => 5,  // seconds
        'retry_delay' => 100000,  // microseconds
    ],
];