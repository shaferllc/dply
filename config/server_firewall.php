<?php

return [

    /**
     * Try root SSH first for UFW/status actions, then fall back to the configured deploy user.
     */
    'use_root_ssh' => (bool) env('SERVER_FIREWALL_USE_ROOT_SSH', true),

    'fallback_to_deploy_user_ssh' => (bool) env('SERVER_FIREWALL_FALLBACK_TO_DEPLOY_SSH', true),

    /*
    |--------------------------------------------------------------------------
    | Server meta keys for the in-flight apply run banner
    |--------------------------------------------------------------------------
    | The workspace banner streams live UFW output during apply. Per-server run
    | state lives under these meta keys (cleared/refreshed each run); the
    | output buffer itself lives in the application cache keyed by run_id.
    */
    'meta_apply_run_id_key' => 'firewall_apply_run_id',
    'meta_apply_status_key' => 'firewall_apply_status',
    'meta_apply_started_at_key' => 'firewall_apply_started_at',
    'meta_apply_finished_at_key' => 'firewall_apply_finished_at',
    'meta_apply_error_key' => 'firewall_apply_error',
    'apply_output_cache_key_prefix' => 'firewall_apply_output:',
    'apply_output_cache_ttl_seconds' => 300,

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
        // ── Web tiers ────────────────────────────────────────────────────────────────────
        'laravel_web' => [
            'label' => 'Laravel web',
            'description' => 'SSH + HTTP + HTTPS — for a basic Laravel app server.',
            'rules' => [
                ['name' => 'SSH', 'port' => 22, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'HTTP', 'port' => 80, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'HTTPS', 'port' => 443, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
        ],
        'web_full_stack' => [
            'label' => 'Web full-stack',
            'description' => 'SSH + HTTP + HTTPS + outbound DNS + ICMPv6 (NDP).',
            'rules' => [
                ['name' => 'SSH', 'port' => 22, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'HTTP', 'port' => 80, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'HTTPS', 'port' => 443, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'DNS (UDP)', 'port' => 53, 'protocol' => 'udp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'ICMPv6', 'port' => null, 'protocol' => 'ipv6-icmp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
        ],

        // ── Database tiers ───────────────────────────────────────────────────────────────
        'db_replica' => [
            'label' => 'DB replica (Postgres)',
            'description' => 'SSH + PostgreSQL inbound — for a managed Postgres replica.',
            'rules' => [
                ['name' => 'SSH', 'port' => 22, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'PostgreSQL', 'port' => 5432, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
        ],
        'mysql_replica' => [
            'label' => 'DB replica (MySQL)',
            'description' => 'SSH + MySQL inbound — for a managed MySQL replica.',
            'rules' => [
                ['name' => 'SSH', 'port' => 22, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'MySQL', 'port' => 3306, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
        ],
        'postgres_inbound' => [
            'label' => 'PostgreSQL only',
            'description' => 'PostgreSQL inbound only (no SSH allow — caller must manage SSH separately).',
            'rules' => [
                ['name' => 'PostgreSQL', 'port' => 5432, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
        ],
        'web_and_db' => [
            'label' => 'Web + DB (single box)',
            'description' => 'SSH + HTTP + HTTPS + Postgres + MySQL — for a colocated single-server stack.',
            'rules' => [
                ['name' => 'SSH', 'port' => 22, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'HTTP', 'port' => 80, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'HTTPS', 'port' => 443, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'PostgreSQL', 'port' => 5432, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'MySQL', 'port' => 3306, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
        ],

        // ── Mail / SMTP ──────────────────────────────────────────────────────────────────
        'mail_relay' => [
            'label' => 'Mail relay',
            'description' => 'SSH + SMTP (25) + Submission (587) + SMTPS (465) — for an outbound mail host.',
            'rules' => [
                ['name' => 'SSH', 'port' => 22, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'SMTP', 'port' => 25, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'Submission', 'port' => 587, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'SMTPS', 'port' => 465, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
        ],

        // ── Monitoring / agents ──────────────────────────────────────────────────────────
        'monitoring_target' => [
            'label' => 'Monitoring target',
            'description' => 'SSH + Node Exporter (9100) for Prometheus scraping.',
            'rules' => [
                ['name' => 'SSH', 'port' => 22, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
                ['name' => 'Node Exporter', 'port' => 9100, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
        ],

        // ── Lockdown ─────────────────────────────────────────────────────────────────────
        'ssh_only' => [
            'label' => 'SSH only (lockdown)',
            'description' => 'Just the SSH allow rule. Combined with UFW default-deny inbound, locks the host down to SSH-only.',
            'rules' => [
                ['name' => 'SSH', 'port' => 22, 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
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
