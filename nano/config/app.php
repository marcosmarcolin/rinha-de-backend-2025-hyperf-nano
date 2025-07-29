<?php

return [
    'redis' => [
        'default' => [
            'host' => 'redis',
            'auth' => null,
            'port' => 6379,
            'db' => 0,
            'pool' => [
                'min_connections' => 5,
                'max_connections' => 250,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60,
            ],
        ]
    ],
    'server' => [
        'settings' => [
            'worker_num' => 1,
            'enable_coroutine' => true,
            'max_conn' => 1024
        ],
    ],
];