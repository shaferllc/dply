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
                'apt-get install -y php-cli php-fpm php-mbstring php-xml php-mysql php-curl php-zip unzip git',
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
                'apt-get install -y php-cli php-fpm php-mbstring php-xml php-mysql php-curl php-zip unzip git',
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
