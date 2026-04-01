<?php

/**
 * Allowlisted remote log files (tail via SSH). Only paths matching allowed_path_prefixes are permitted.
 *
 * Placeholders:
 *   {php_version} — from the first site on the server with php_version, else default_php_version.
 *   {ssh_user}    — server SSH username (for under /home/{user}/…).
 */
return [

    /**
     * Extra time for this request while tailing logs over SSH (PHP max_execution_time is often 30s).
     * Set to 0 to leave the global limit unchanged.
     */
    'request_time_budget_seconds' => (int) env('SERVER_LOG_REQUEST_TIME_BUDGET', 90),

    /**
     * Cache lock TTL for log-viewer fetches (must cover the full SSH tail, not only HTTP defaults).
     */
    'fetch_lock_seconds' => (int) env('SERVER_LOG_FETCH_LOCK_SECONDS', 120),

    /**
     * Max seconds to wait for the per-server log lock before giving up (poll vs user-initiated).
     */
    'fetch_lock_wait_poll_seconds' => (int) env('SERVER_LOG_FETCH_LOCK_WAIT_POLL', 10),

    'fetch_lock_wait_manual_seconds' => (int) env('SERVER_LOG_FETCH_LOCK_WAIT_MANUAL', 15),

    /**
     * Max bytes of log text kept in the Livewire snapshot (very large payloads can fail to hydrate).
     */
    'max_stored_bytes' => (int) env('SERVER_LOG_MAX_STORED_BYTES', 524288),

    /**
     * Max bytes sent on Reverb per snapshot (WebSocket frame limits). Truncates further if needed.
     */
    'max_broadcast_bytes' => (int) env('SERVER_LOG_MAX_BROADCAST_BYTES', 131072),

    /** When a time-range filter is active, fetch at least this many lines before client-side timestamp filtering (file logs). */
    'time_range_min_tail_lines' => (int) env('SERVER_LOG_TIME_RANGE_MIN_TAIL_LINES', 2500),

    'max_share_bytes' => (int) env('SERVER_LOG_MAX_SHARE_BYTES', 524288),

    'tail_lines' => (int) env('SERVER_LOG_TAIL_LINES', 500),

    /**
     * Default visible lines in log viewers (viewport height before scroll). Per-server override: meta log_ui_display_lines (UI clamp 2–50).
     */
    'display_lines' => (int) env('SERVER_LOG_DISPLAY_LINES', 18),

    'default_php_version' => env('SERVER_LOG_DEFAULT_PHP_VERSION', '8.3'),

    /**
     * When true, paths under /var/log/ and /root/ are read over SSH as root first (same key must
     * authorize root), then the deploy user if root login fails and fallback is enabled.
     * Paths under /home/ always use the server’s configured SSH user.
     */
    'read_system_log_paths_as_root' => (bool) env('SERVER_LOG_READ_SYSTEM_AS_ROOT', true),

    /**
     * If root SSH fails or the file is unreadable as that user, retry with the deploy SSH user.
     */
    'fallback_to_deploy_user_for_logs' => (bool) env('SERVER_LOG_FALLBACK_TO_DEPLOY_USER', true),

    'allowed_path_prefixes' => [
        '/var/log/',
        '/home/',
        '/root/',
    ],

    /**
     * Units allowed for journalctl-based sources (type => journal). No user-supplied unit strings.
     */
    'journal_allowed_units' => [
        'nginx',
        'nginx.service',
        'apache2',
        'apache2.service',
        'caddy',
        'caddy.service',
        'traefik',
        'traefik.service',
        'mysql',
        'mysql.service',
        'mariadb',
        'mariadb.service',
        'php8.1-fpm',
        'php8.1-fpm.service',
        'php8.2-fpm',
        'php8.2-fpm.service',
        'php8.3-fpm',
        'php8.3-fpm.service',
        'php8.4-fpm',
        'php8.4-fpm.service',
    ],

    'sources' => [

        'dply_activity' => [
            'type' => 'dply',
            'label' => 'Dply activity',
            'description' => 'Organization audit events for this server and its sites.',
            'group' => 'dply',
        ],

        'nginx_error' => [
            'type' => 'file',
            'label' => 'Nginx error log',
            'path' => '/var/log/nginx/error.log',
            'group' => 'nginx',
        ],

        'nginx_access' => [
            'type' => 'file',
            'label' => 'Nginx access log',
            'path' => '/var/log/nginx/access.log',
            'group' => 'nginx',
        ],

        'journal_nginx' => [
            'type' => 'journal',
            'label' => 'Journal: nginx',
            'unit' => 'nginx.service',
            'group' => 'nginx',
        ],

        'apache_error' => [
            'type' => 'file',
            'label' => 'Apache error log',
            'path' => '/var/log/apache2/error.log',
            'group' => 'apache',
        ],

        'apache_access' => [
            'type' => 'file',
            'label' => 'Apache access log',
            'path' => '/var/log/apache2/access.log',
            'group' => 'apache',
        ],

        'journal_apache' => [
            'type' => 'journal',
            'label' => 'Journal: apache',
            'unit' => 'apache2.service',
            'group' => 'apache',
        ],

        'openlitespeed_error' => [
            'type' => 'file',
            'label' => 'OpenLiteSpeed error log',
            'path' => '/var/log/lshttpd/error.log',
            'group' => 'openlitespeed',
        ],

        'traefik_log' => [
            'type' => 'file',
            'label' => 'Traefik log',
            'path' => '/var/log/traefik/traefik.log',
            'group' => 'traefik',
        ],

        'traefik_access' => [
            'type' => 'file',
            'label' => 'Traefik access log',
            'path' => '/var/log/traefik/access.log',
            'group' => 'traefik',
        ],

        'journal_traefik' => [
            'type' => 'journal',
            'label' => 'Journal: traefik',
            'unit' => 'traefik.service',
            'group' => 'traefik',
        ],

        'journal_php_fpm' => [
            'type' => 'journal',
            'label' => 'Journal: PHP-FPM',
            'unit_template' => 'php{php_version}-fpm.service',
            'group' => 'php',
        ],

        'journal_mysql' => [
            'type' => 'journal',
            'label' => 'Journal: MySQL/MariaDB',
            'unit' => 'mysql.service',
            'group' => 'database',
        ],

        'letsencrypt' => [
            'type' => 'file',
            'label' => "Let's Encrypt log",
            'path' => '/var/log/letsencrypt/letsencrypt.log',
            'group' => 'ssl',
        ],

        'ufw' => [
            'type' => 'file',
            'label' => 'UFW log',
            'path' => '/var/log/ufw.log',
            'group' => 'firewall',
        ],

        'supervisor' => [
            'type' => 'file',
            'label' => 'Supervisor log',
            'path' => '/var/log/supervisor/supervisord.log',
            'group' => 'daemons',
        ],

        'auth' => [
            'type' => 'file',
            'label' => 'SSH / auth log',
            'path' => '/var/log/auth.log',
            'group' => 'security',
        ],

        'redis' => [
            'type' => 'file',
            'label' => 'Redis log',
            'path' => '/var/log/redis/redis-server.log',
            'group' => 'services',
        ],

        'php_fpm' => [
            'type' => 'file',
            'label' => 'PHP-FPM log',
            'path' => '/var/log/php{php_version}-fpm.log', // resolved e.g. php8.3-fpm.log
            'group' => 'php',
        ],

        'mysql_error' => [
            'type' => 'file',
            'label' => 'MySQL error log',
            'path' => '/var/log/mysql/error.log',
            'group' => 'database',
        ],

        'syslog' => [
            'type' => 'file',
            'label' => 'System log (tail)',
            'path' => '/var/log/syslog',
            'group' => 'system',
        ],
    ],
];
