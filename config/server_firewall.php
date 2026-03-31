<?php

return [

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
    | Policy packs (one click: merge multiple bundled templates)
    |--------------------------------------------------------------------------
    |
    | Keys reference server_firewall.bundled_templates. Order is preserved.
    |
    */
    'policy_packs' => [
        'full_stack' => [
            'label' => 'Web + PostgreSQL',
            'description' => 'Laravel web (SSH/HTTP/HTTPS) plus PostgreSQL without duplicating SSH.',
            'bundled_templates' => ['laravel_web', 'postgres_inbound'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloud provider SG mirror (roadmap)
    |--------------------------------------------------------------------------
    */
    'provider_sync' => [
        'enabled' => filter_var(env('SERVER_FIREWALL_PROVIDER_SYNC', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional HTTP probe after apply (queue job)
    |--------------------------------------------------------------------------
    */
    'synthetic_probe' => [
        'dispatch_after_apply' => filter_var(env('SERVER_FIREWALL_SYNTHETIC_PROBE_AFTER_APPLY', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Danger zone (read-only host introspection)
    |--------------------------------------------------------------------------
    */
    'danger_zone' => [
        'iptables_counters_enabled' => filter_var(env('SERVER_FIREWALL_IPTABLES_COUNTERS', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Docs / cross-links (UI copy)
    |--------------------------------------------------------------------------
    */
    'docs' => [
        'fail2ban' => 'https://github.com/fail2ban/fail2ban',
    ],

    /*
    |--------------------------------------------------------------------------
    | Organization-level settings (organizations.firewall_settings JSON)
    |--------------------------------------------------------------------------
    |
    | Admins edit these under Settings → Servers & Sites. Stored keys are merged
    | with defaults; unknown keys are preserved for forward compatibility.
    |
    */
    'organization_settings' => [
        'require_second_approval' => false,
        'notify_drift_webhook' => false,
        'synthetic_probe_url' => null,
    ],

];
