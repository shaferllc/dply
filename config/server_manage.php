<?php

/**
 * Server “Manage” workspace: allowlisted read paths and fixed service scripts only.
 * No user-controlled shell fragments.
 */
return [

    /**
     * When true, configuration previews and service actions run in a queue worker instead of
     * blocking the Livewire HTTP request. Requires a running worker unless QUEUE_CONNECTION=sync.
     */
    'queue_remote_tasks' => (bool) env('SERVER_MANAGE_QUEUE_REMOTE_TASKS', true),

    /**
     * Optional queue name for manage SSH jobs (null = default queue).
     */
    'remote_task_queue' => env('SERVER_MANAGE_REMOTE_TASK_QUEUE'),

    /** Cache TTL (seconds) for in-flight manage task status shown while the UI polls. */
    'remote_task_cache_ttl_seconds' => (int) env('SERVER_MANAGE_REMOTE_TASK_CACHE_TTL', 900),

    /** How often the worker writes partial SSH output to cache while the command runs. */
    'remote_task_cache_flush_seconds' => (float) env('SERVER_MANAGE_REMOTE_TASK_CACHE_FLUSH', 0.5),

    /**
     * When true, a new manage task for the same server + task name invalidates the previous job so it
     * exits instead of blocking the worker or finishing after the UI moved on to the newer request.
     */
    'supersede_duplicate_remote_tasks' => (bool) env('SERVER_MANAGE_SUPERSEDE_DUPLICATE_REMOTE_TASKS', true),

    /**
     * If a job stays in "queued" longer than this (seconds), the UI hints that a queue worker may be down.
     */
    'remote_task_stalled_queued_seconds' => (int) env('SERVER_MANAGE_REMOTE_TASK_STALLED_QUEUED', 45),

    /**
     * Run manage previews and service scripts as root over SSH (TaskRunner). Requires the stored
     * key to be authorized for root. When false, runs as the server SSH user (needs passwordless sudo).
     */
    'use_root_ssh' => (bool) env('SERVER_MANAGE_USE_ROOT_SSH', true),

    /**
     * If root SSH fails, retry the same operation as the deploy SSH user.
     */
    'fallback_to_deploy_user_ssh' => (bool) env('SERVER_MANAGE_FALLBACK_TO_DEPLOY_SSH', true),

    'allowed_config_path_prefixes' => [
        '/etc/nginx/',
        '/etc/mysql/',
        '/etc/mariadb/',
        '/etc/redis/',
        '/etc/php/',
        '/etc/supervisor/',
    ],

    /** Max bytes read when previewing a config file over SSH. */
    'config_preview_max_bytes' => 48_000,

    /**
     * Configuration files to preview (first N bytes via head -c).
     * Keys are stable identifiers for Livewire actions.
     */
    'config_previews' => [
        'nginx' => [
            'label' => 'NGINX configuration',
            'path' => '/etc/nginx/nginx.conf',
        ],
        'mysql' => [
            'label' => 'MySQL / MariaDB (Debian defaults)',
            'path' => '/etc/mysql/my.cnf',
        ],
        'redis' => [
            'label' => 'Redis configuration',
            'path' => '/etc/redis/redis.conf',
        ],
    ],

    /**
     * Service actions: inline bash run as the server SSH user (may require passwordless sudo).
     */
    'service_actions' => [
        'restart_nginx' => [
            'label' => 'Restart NGINX',
            'description' => 'systemctl or service restart for nginx.',
            'confirm' => 'Restart NGINX now? Sites may briefly show errors.',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl restart nginx || systemctl restart nginx) 2>&1
else
  (sudo -n service nginx restart || service nginx restart) 2>&1
fi
BASH
        ],
        'reload_nginx' => [
            'label' => 'Reload NGINX',
            'description' => 'Graceful reload of configuration.',
            'confirm' => 'Reload NGINX configuration?',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl reload nginx || systemctl reload nginx) 2>&1
else
  (sudo -n service nginx reload || service nginx reload) 2>&1
fi
BASH
        ],
        'restart_php_fpm' => [
            'label' => 'Restart PHP-FPM',
            'description' => 'Uses php{version}-fpm from server meta default_php_version or 8.3.',
            'confirm' => 'Restart PHP-FPM workers? In-flight requests may fail briefly.',
            'timeout' => 180,
            'script' => <<<'BASH'
V="${DPLY_PHP_VERSION:-8.3}"
UNIT="php${V}-fpm"
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl restart "$UNIT" || systemctl restart "$UNIT") 2>&1
else
  (sudo -n service "$UNIT" restart || service "$UNIT" restart) 2>&1
fi
BASH
        ],
        'apt_update' => [
            'label' => 'Refresh package lists',
            'description' => 'Runs apt-get update only (no upgrades). Debian/Ubuntu guests.',
            'confirm' => 'Run apt-get update on this server?',
            'timeout' => 300,
            'script' => <<<'BASH'
(sudo -n apt-get update || apt-get update) 2>&1
BASH
        ],
        'restart_redis' => [
            'label' => 'Restart Redis',
            'description' => 'redis-server service.',
            'confirm' => 'Restart Redis? Cache and queues using Redis will be disrupted.',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl restart redis-server || sudo -n systemctl restart redis || systemctl restart redis-server || systemctl restart redis) 2>&1
else
  (sudo -n service redis-server restart || service redis-server restart) 2>&1
fi
BASH
        ],
        'restart_mysql' => [
            'label' => 'Restart MySQL',
            'description' => 'mysql or mariadb service.',
            'confirm' => 'Restart the database server? This will interrupt connections.',
            'timeout' => 300,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl restart mysql || sudo -n systemctl restart mariadb || systemctl restart mysql || systemctl restart mariadb) 2>&1
else
  (sudo -n service mysql restart || service mysql restart) 2>&1
fi
BASH
        ],
    ],

    'dangerous_actions' => [
        'reboot' => [
            'label' => 'Reboot server',
            'description' => 'Schedules an immediate reboot (requires permission on the guest).',
            'confirm' => 'Reboot this server now? SSH will drop until it comes back.',
            'timeout' => 60,
            'script' => <<<'BASH'
(sudo -n reboot || sudo -n shutdown -r now || reboot) 2>&1 &
echo "Reboot requested."
BASH
        ],
    ],

    'auto_update_intervals' => [
        'off' => 'Disabled',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
    ],

];
