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
        '/etc/apt/apt.conf.d/',
    ],

    /** Exact-match allowed config paths (used in addition to allowed_config_path_prefixes). */
    'allowed_config_paths_exact' => [
        '/etc/ssh/sshd_config',
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
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'reload_php_fpm' => [
            'label' => 'Reload PHP-FPM',
            'description' => 'Graceful reload (USR2) of php{version}-fpm. Uses default_php_version meta or 8.3.',
            'confirm' => 'Reload PHP-FPM workers gracefully?',
            'timeout' => 120,
            'script' => <<<'BASH'
V="${DPLY_PHP_VERSION:-8.3}"
UNIT="php${V}-fpm"
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl reload "$UNIT" || systemctl reload "$UNIT") 2>&1
else
  (sudo -n service "$UNIT" reload || service "$UNIT" reload) 2>&1
fi
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'nginx_test_config' => [
            'label' => 'Test nginx config',
            'description' => 'Runs nginx -t to validate configuration without reloading.',
            'confirm' => 'Test the nginx configuration now?',
            'timeout' => 60,
            'script' => '(sudo -n nginx -t 2>&1 || nginx -t 2>&1)',
        ],

        'apt_upgrade' => [
            'label' => 'Install all upgrades',
            'description' => 'apt-get -y upgrade. Long-running; may restart services.',
            'confirm' => 'Install all available upgrades? Some services may restart.',
            'timeout' => 1800,
            'script' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
(sudo -n apt-get -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" upgrade \
  || apt-get -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" upgrade) 2>&1
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'apt_dist_upgrade' => [
            'label' => 'Distro upgrade',
            'description' => 'apt-get -y dist-upgrade. May replace held packages and require reboot.',
            'confirm' => 'Run apt-get dist-upgrade? Held packages may change; reboot may be required.',
            'timeout' => 1800,
            'script' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
(sudo -n apt-get -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" dist-upgrade \
  || apt-get -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" dist-upgrade) 2>&1
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'apt_autoremove' => [
            'label' => 'Autoremove unused',
            'description' => 'apt-get -y autoremove. Removes unused dependencies.',
            'confirm' => 'Remove unused package dependencies?',
            'timeout' => 300,
            'script' => 'export DEBIAN_FRONTEND=noninteractive; (sudo -n apt-get -y autoremove || apt-get -y autoremove) 2>&1',
            'rerun_probe_after_finish' => true,
        ],

        'apt_clean' => [
            'label' => 'Clean apt cache',
            'description' => 'apt-get clean. Frees /var/cache/apt/archives.',
            'confirm' => 'Clean the apt cache?',
            'timeout' => 60,
            'script' => '(sudo -n apt-get clean || apt-get clean) 2>&1; echo "---"; df -h /var 2>/dev/null',
        ],

        'unattended_upgrades_enable' => [
            'label' => 'Enable unattended-upgrades',
            'description' => 'Writes /etc/apt/apt.conf.d/20auto-upgrades enabling daily update lists and unattended upgrades.',
            'confirm' => 'Enable unattended-upgrades on this server?',
            'timeout' => 60,
            'script' => <<<'BASH'
TMP=$(mktemp)
cat > "$TMP" <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
(sudo -n install -m 0644 "$TMP" /etc/apt/apt.conf.d/20auto-upgrades \
  || install -m 0644 "$TMP" /etc/apt/apt.conf.d/20auto-upgrades) 2>&1
rm -f "$TMP"
echo "---"
cat /etc/apt/apt.conf.d/20auto-upgrades
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'unattended_upgrades_disable' => [
            'label' => 'Disable unattended-upgrades',
            'description' => 'Writes /etc/apt/apt.conf.d/20auto-upgrades disabling automatic upgrades.',
            'confirm' => 'Disable unattended-upgrades on this server?',
            'timeout' => 60,
            'script' => <<<'BASH'
TMP=$(mktemp)
cat > "$TMP" <<'EOF'
APT::Periodic::Update-Package-Lists "0";
APT::Periodic::Unattended-Upgrade "0";
EOF
(sudo -n install -m 0644 "$TMP" /etc/apt/apt.conf.d/20auto-upgrades \
  || install -m 0644 "$TMP" /etc/apt/apt.conf.d/20auto-upgrades) 2>&1
rm -f "$TMP"
echo "---"
cat /etc/apt/apt.conf.d/20auto-upgrades
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'certbot_renew_dry_run' => [
            'label' => 'Dry-run renew',
            'description' => 'certbot renew --dry-run. Safe — does not change certs on disk.',
            'confirm' => 'Run certbot renew --dry-run?',
            'timeout' => 600,
            'script' => '(sudo -n certbot renew --dry-run --no-color 2>&1 || certbot renew --dry-run --no-color 2>&1)',
        ],

        'certbot_renew_all' => [
            'label' => 'Renew certificates',
            'description' => 'certbot renew. Renews any cert near expiry.',
            'confirm' => 'Renew certificates? Only those within the renewal window will actually renew.',
            'timeout' => 900,
            'script' => '(sudo -n certbot renew --no-color 2>&1 || certbot renew --no-color 2>&1)',
            'rerun_probe_after_finish' => true,
        ],

        'redis_info' => [
            'label' => 'Show Redis INFO',
            'description' => 'redis-cli INFO snapshot.',
            'confirm' => 'Show Redis INFO?',
            'timeout' => 30,
            'script' => 'redis-cli INFO 2>&1 | head -n 200',
        ],

        'mysql_processlist' => [
            'label' => 'MySQL processlist',
            'description' => 'SHOW PROCESSLIST. Requires manage_internal_db_password if root needs auth.',
            'confirm' => 'Show MySQL processlist?',
            'timeout' => 30,
            'script' => <<<'BASH'
if [ -n "${DPLY_DB_PASSWORD:-}" ]; then
  mysql -uroot -p"$DPLY_DB_PASSWORD" -e "SHOW PROCESSLIST" 2>&1 | head -n 200
else
  (sudo -n mysql -e "SHOW PROCESSLIST" 2>&1 || mysql -e "SHOW PROCESSLIST" 2>&1) | head -n 200
fi
BASH,
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

    /**
     * Server "Manage" workspace sub-pages (URL segment after /manage/). Order matches the tab bar.
     */
    'workspace_tabs' => [
        'overview' => ['label' => 'Overview', 'icon' => 'squares-2x2'],
        'services' => ['label' => 'Services', 'icon' => 'bolt'],
        'web' => ['label' => 'Web', 'icon' => 'globe-alt'],
        'data' => ['label' => 'Data', 'icon' => 'circle-stack'],
        'updates' => ['label' => 'Updates', 'icon' => 'arrow-path'],
        'configuration' => ['label' => 'Configuration', 'icon' => 'document-text'],
        'danger' => ['label' => 'Danger', 'icon' => 'exclamation-triangle'],
    ],

];
