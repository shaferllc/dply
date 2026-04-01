<?php

return [

    /**
     * Try root SSH first for UFW/status actions, then fall back to the configured deploy user.
     */
    'use_root_ssh' => (bool) env('SERVER_FIREWALL_USE_ROOT_SSH', true),

    'fallback_to_deploy_user_ssh' => (bool) env('SERVER_FIREWALL_FALLBACK_TO_DEPLOY_SSH', true),

    /*
    |--------------------------------------------------------------------------
    | New rule form defaults
    |--------------------------------------------------------------------------
    |
    | Used when opening the add-rule form and after save/cancel. Override via .env
    | for your fleet (e.g. default to HTTPS or a trusted office CIDR).
    |
    */
    'new_rule' => [
        'port' => (int) env('SERVER_FIREWALL_DEFAULT_PORT', 443),
        'protocol' => env('SERVER_FIREWALL_DEFAULT_PROTOCOL', 'tcp'),
        'source' => env('SERVER_FIREWALL_DEFAULT_SOURCE', 'any'),
        'action' => env('SERVER_FIREWALL_DEFAULT_ACTION', 'allow'),
        'enabled' => filter_var(env('SERVER_FIREWALL_DEFAULT_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quick-fill presets (add-rule form only)
    |--------------------------------------------------------------------------
    |
    | Keys are stable for wire:click; labels are shown in the UI.
    |
    */
    'presets' => [
        'http' => [
            'label' => 'HTTP',
            'name' => 'HTTP',
            'port' => 80,
            'protocol' => 'tcp',
            'source' => 'any',
            'action' => 'allow',
            'enabled' => true,
        ],
        'https' => [
            'label' => 'HTTPS',
            'name' => 'HTTPS',
            'port' => 443,
            'protocol' => 'tcp',
            'source' => 'any',
            'action' => 'allow',
            'enabled' => true,
        ],
        'ssh' => [
            'label' => 'SSH',
            'name' => 'SSH',
            'port' => 22,
            'protocol' => 'tcp',
            'source' => 'any',
            'action' => 'allow',
            'enabled' => true,
        ],
        'postgres' => [
            'label' => 'PostgreSQL',
            'name' => 'PostgreSQL',
            'port' => 5432,
            'protocol' => 'tcp',
            'source' => 'any',
            'action' => 'allow',
            'enabled' => true,
        ],
        'mysql' => [
            'label' => 'MySQL',
            'name' => 'MySQL',
            'port' => 3306,
            'protocol' => 'tcp',
            'source' => 'any',
            'action' => 'allow',
            'enabled' => true,
        ],
        'redis' => [
            'label' => 'Redis',
            'name' => 'Redis',
            'port' => 6379,
            'protocol' => 'tcp',
            'source' => 'any',
            'action' => 'allow',
            'enabled' => true,
        ],
        'dns_udp' => [
            'label' => 'DNS',
            'name' => 'DNS (UDP)',
            'port' => 53,
            'protocol' => 'udp',
            'source' => 'any',
            'action' => 'allow',
            'enabled' => true,
        ],
        'icmpv6' => [
            'label' => 'ICMPv6 (NDP)',
            'name' => 'ICMPv6',
            'port' => null,
            'protocol' => 'ipv6-icmp',
            'source' => 'any',
            'action' => 'allow',
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | One-click bundles (merged into the current rule list; does not replace)
    |--------------------------------------------------------------------------
    */
    'bundled_templates' => [
        'laravel_web' => [
            'label' => 'Laravel web',
            'rules' => [
                ['name' => 'SSH', 'port' => 22, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'HTTP', 'port' => 80, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'HTTPS', 'port' => 443, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
        ],
        'db_replica' => [
            'label' => 'DB replica',
            'rules' => [
                ['name' => 'SSH', 'port' => 22, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'PostgreSQL', 'port' => 5432, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
        ],
        'postgres_inbound' => [
            'label' => 'PostgreSQL only',
            'rules' => [
                ['name' => 'PostgreSQL', 'port' => 5432, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Docs / cross-links (UI copy)
    |--------------------------------------------------------------------------
    */
    'docs' => [
        'fail2ban' => 'https://github.com/fail2ban/fail2ban',
    ],
];
