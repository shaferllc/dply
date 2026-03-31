<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Setup scripts (run after DigitalOcean droplet is ready)
    |--------------------------------------------------------------------------
    | Each script has: id (key), name (label), commands (array of shell commands).
    | Commands run in sequence; each has a 300s timeout. Use "none" or omit to skip.
    */
    'scripts' => [
        'laravel' => [
            'name' => 'Laravel (PHP)',
            'commands' => [
                'export DEBIAN_FRONTEND=noninteractive',
                'apt-get update -y',
                // Laravel server requirements + typical stack: DB drivers, Redis, images, intl, sodium.
                'apt-get install -y ca-certificates curl git unzip '
                    .'php-bcmath php-cli php-curl php-fpm php-gd php-intl php-mbstring php-mysql '
                    .'php-pgsql php-redis php-sodium php-sqlite3 php-xml php-zip',
                'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer',
            ],
        ],
        'node' => [
            'name' => 'Node',
            'commands' => [
                'curl -fsSL https://deb.nodesource.com/setup_20.x | bash -',
                'apt-get install -y nodejs',
            ],
        ],
        'laravel_node' => [
            'name' => 'Laravel + Node',
            'commands' => [
                'export DEBIAN_FRONTEND=noninteractive',
                'apt-get update -y',
                'apt-get install -y ca-certificates curl git unzip '
                    .'php-bcmath php-cli php-curl php-fpm php-gd php-intl php-mbstring php-mysql '
                    .'php-pgsql php-redis php-sodium php-sqlite3 php-xml php-zip',
                'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer',
                'curl -fsSL https://deb.nodesource.com/setup_20.x | bash -',
                'apt-get install -y nodejs',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout per command (seconds)
    |--------------------------------------------------------------------------
    */
    'command_timeout' => 300,
];
