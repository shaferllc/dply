<?php

/**
 * Optional remediation links shown in insight notifications and Insights UI.
 *
 * @var array<string, array{label: string, url: string}>
 */
return [
    'cpu_ram_usage' => [
        'label' => 'Capacity planning',
        'url' => 'https://laravel.com/docs/deployment#optimization',
    ],
    'load_average_high' => [
        'label' => 'Tune workers',
        'url' => 'https://nginx.org/en/docs/ngx_core_module.html#worker_processes',
    ],
    'ssl_certificate_checks' => [
        'label' => 'Renew SSL',
        'url' => 'https://letsencrypt.org/docs/',
    ],
    'php_eol_sites' => [
        'label' => 'PHP upgrade guide',
        'url' => 'https://www.php.net/supported-versions.php',
    ],
    'supervisor_running' => [
        'label' => 'Supervisor docs',
        'url' => 'http://supervisord.org/',
    ],
];
