<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Deploy command templates
    |--------------------------------------------------------------------------
    | Quick-fill templates for the server deploy command. Key = id, name = label,
    | command = default deploy script (user can edit after applying).
    */
    'templates' => [
        'laravel' => [
            'name' => 'Laravel',
            'command' => 'cd /var/www && git pull && composer install --no-dev && php artisan migrate --force',
        ],
        'node' => [
            'name' => 'Node (npm)',
            'command' => 'cd /var/www && git pull && npm ci && npm run build',
        ],
        'node_pm2' => [
            'name' => 'Node (PM2)',
            'command' => 'cd /var/www && git pull && npm ci && npm run build && pm2 reload all',
        ],
        'static' => [
            'name' => 'Static (git pull)',
            'command' => 'cd /var/www && git pull',
        ],
    ],
];
