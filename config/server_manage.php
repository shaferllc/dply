<?php

use App\Services\Servers\ServerConfigFileCatalog;

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
     * When a manage SSH script fails with apt/dpkg lock contention (exit 100 or lock
     * messages), re-queue the same job with backoff up to this many attempts.
     */
    'apt_auto_retry_max_attempts' => max(1, min(6, (int) env('SERVER_MANAGE_APT_AUTO_RETRY_MAX', 3))),

    /** Seconds between apt-lock auto-retries (multiplied by attempt number). */
    'apt_auto_retry_delay_seconds' => max(5, min(120, (int) env('SERVER_MANAGE_APT_AUTO_RETRY_DELAY', 15))),

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
        '/etc/caddy/',
        '/etc/apache2/',
        '/usr/local/lsws/conf/',
        '/etc/traefik/',
        '/etc/haproxy/',
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
        'caddy' => [
            'label' => 'Caddyfile',
            'path' => '/etc/caddy/Caddyfile',
        ],
        'apache' => [
            'label' => 'Apache configuration',
            'path' => '/etc/apache2/apache2.conf',
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
     * Webserver-specific config file roots for the in-app editor. The editor
     * lists files under these globs (main config + per-site fragments) and
     * lets the operator pick one to edit. Paths returned by the discovery
     * step are always checked against `allowed_config_path_prefixes` before
     * read or write, so adding a glob here doesn't widen the security
     * boundary on its own.
     */
    'webserver_config_layout' => [
        'nginx' => [
            'main' => '/etc/nginx/nginx.conf',
            'globs' => [
                '/etc/nginx/sites-available/*',
                '/etc/nginx/conf.d/*.conf',
            ],
            'validate' => '(sudo -n nginx -t 2>&1 || nginx -t 2>&1)',
            'reload' => '(sudo -n systemctl reload nginx || systemctl reload nginx) 2>&1',
            'log_dir' => '/var/log/nginx',
            'access_log' => '/var/log/nginx/access.log',
            'error_log' => '/var/log/nginx/error.log',
        ],
        'caddy' => [
            'main' => '/etc/caddy/Caddyfile',
            'globs' => [
                '/etc/caddy/sites-enabled/*.caddy',
                '/etc/caddy/Caddyfile.d/*',
            ],
            'validate' => '(sudo -n caddy validate --config /etc/caddy/Caddyfile 2>&1 || caddy validate --config /etc/caddy/Caddyfile 2>&1)',
            'reload' => '(sudo -n systemctl reload caddy || systemctl reload caddy) 2>&1',
            'log_dir' => '/var/log/caddy',
            // Caddy access logs are per-site (var/log/caddy/<basename>-access.log).
            // The "log" tab falls back to journalctl when the per-site files aren't
            // a single canonical pair.
            'access_log' => null,
            'error_log' => null,
            'journal_unit' => 'caddy',
        ],
        'apache' => [
            'main' => '/etc/apache2/apache2.conf',
            'globs' => [
                '/etc/apache2/sites-available/*.conf',
                '/etc/apache2/conf-available/*.conf',
                '/etc/apache2/mods-available/*.conf',
                '/etc/apache2/ports.conf',
            ],
            'validate' => '(sudo -n apachectl configtest 2>&1 || apachectl configtest 2>&1)',
            'reload' => '(sudo -n systemctl reload apache2 || systemctl reload apache2) 2>&1',
            'log_dir' => '/var/log/apache2',
            'access_log' => '/var/log/apache2/access.log',
            'error_log' => '/var/log/apache2/error.log',
        ],
        'openlitespeed' => [
            'main' => '/usr/local/lsws/conf/httpd_config.conf',
            'globs' => [
                '/usr/local/lsws/conf/vhosts/*/vhconf.conf',
                '/usr/local/lsws/conf/templates/*.conf',
                '/usr/local/lsws/conf/admin/admin_config.conf',
                '/usr/local/lsws/conf/mime.properties',
            ],
            'validate' => '(sudo -n /usr/local/lsws/bin/lshttpd -t 2>&1 || /usr/local/lsws/bin/lshttpd -t 2>&1)',
            'reload' => '(sudo -n systemctl reload lshttpd || systemctl reload lshttpd) 2>&1',
            'log_dir' => '/usr/local/lsws/logs',
            'access_log' => '/usr/local/lsws/logs/access.log',
            'error_log' => '/usr/local/lsws/logs/error.log',
        ],
        'traefik' => [
            'main' => '/etc/traefik/traefik.yml',
            'globs' => [
                '/etc/traefik/dynamic/*.yml',
                '/etc/traefik/dynamic/*.yaml',
            ],
            // Traefik has no parse-only flag. The Caddy backends carry the
            // bulk of the routing/serving logic, and Caddy DOES have native
            // validate — so that's what we use here.
            'validate' => '(sudo -n caddy validate --config /etc/caddy/Caddyfile 2>&1 || caddy validate --config /etc/caddy/Caddyfile 2>&1)',
            'reload' => '(sudo -n systemctl restart traefik || systemctl restart traefik) 2>&1',
            // Traefik logs default to stdout/stderr (visible via journalctl);
            // file-based access logs are opt-in via traefik.yml. Fall back
            // to the journal unit so the Logs tab has something to show.
            'log_dir' => null,
            'access_log' => null,
            'error_log' => null,
            'journal_unit' => 'traefik',
        ],
        'haproxy' => [
            'main' => '/etc/haproxy/haproxy.cfg',
            'globs' => [
                // Operators sometimes split haproxy into multiple files under
                // /etc/haproxy/conf.d/; expose those if present.
                '/etc/haproxy/conf.d/*.cfg',
            ],
            'validate' => '(sudo -n haproxy -c -f /etc/haproxy/haproxy.cfg 2>&1 || haproxy -c -f /etc/haproxy/haproxy.cfg 2>&1)',
            'reload' => '(sudo -n systemctl reload haproxy || systemctl reload haproxy) 2>&1',
            // HAProxy logs default to syslog (rsyslog routes to /var/log/haproxy.log
            // on most distros). Some installs go to journal-only; the Logs tab
            // falls back to journalctl when the file isn't present.
            'log_dir' => '/var/log',
            'access_log' => '/var/log/haproxy.log',
            'error_log' => null,
            'journal_unit' => 'haproxy',
        ],
    ],

    /** Max bytes that the in-app editor will allow saving (sanity cap, NOT a security boundary). */
    'config_edit_max_bytes' => 256_000,

    /** How many timestamped backups to keep per file under /etc/<engine>/_dply_backups/. */
    'config_edit_backup_keep' => 10,

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

        // Note: every *_test_config script wraps the parse-only validator in a
        // `cmd && echo "[ok] …" || { echo "[error] …"; exit 1; }` trailer so
        // the banner shows a clear pass/fail summary on top of the binary's
        // own output. The `set -euo pipefail` wrapper around inline bash
        // doesn't trip on `cmd && a || b` chains, so this stays safe.
        'nginx_test_config' => [
            'label' => 'Test nginx config',
            'description' => 'Runs nginx -t to validate configuration without reloading.',
            'confirm' => 'Test the nginx configuration now?',
            'timeout' => 60,
            'script' => '(sudo -n nginx -t 2>&1 || nginx -t 2>&1) && echo "[ok] nginx config is valid." || { echo "[error] nginx config validation failed."; exit 1; }',
        ],

        // Caddy service actions — mirror the nginx triad. Registered so the
        // active "Caddy" card on /manage/web has working controls once an
        // operator switches to caddy.
        'restart_caddy' => [
            'label' => 'Restart Caddy',
            'description' => 'systemctl restart caddy. Sites may briefly show errors.',
            'confirm' => 'Restart Caddy now? Sites may briefly show errors.',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl restart caddy || systemctl restart caddy) 2>&1
else
  (sudo -n service caddy restart || service caddy restart) 2>&1
fi
BASH
        ],
        'reload_caddy' => [
            'label' => 'Reload Caddy',
            'description' => 'Graceful reload of Caddyfile.',
            'confirm' => 'Reload Caddy configuration?',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl reload caddy || systemctl reload caddy) 2>&1
else
  (sudo -n service caddy reload || service caddy reload) 2>&1
fi
BASH
        ],
        'caddy_test_config' => [
            'label' => 'Test Caddy config',
            'description' => 'Runs caddy validate to check Caddyfile syntax.',
            'confirm' => 'Test the Caddy configuration now?',
            'timeout' => 60,
            'script' => '(sudo -n caddy validate --config /etc/caddy/Caddyfile 2>&1 || caddy validate --config /etc/caddy/Caddyfile 2>&1)',
        ],

        // Apache service actions — analogous to nginx + caddy.
        'restart_apache' => [
            'label' => 'Restart Apache',
            'description' => 'systemctl restart apache2.',
            'confirm' => 'Restart Apache now? Sites may briefly show errors.',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl restart apache2 || systemctl restart apache2) 2>&1
else
  (sudo -n service apache2 restart || service apache2 restart) 2>&1
fi
BASH
        ],
        'reload_apache' => [
            'label' => 'Reload Apache',
            'description' => 'Graceful reload of Apache configuration.',
            'confirm' => 'Reload Apache configuration?',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl reload apache2 || systemctl reload apache2) 2>&1
else
  (sudo -n service apache2 reload || service apache2 reload) 2>&1
fi
BASH
        ],
        'apache_test_config' => [
            'label' => 'Test Apache config',
            'description' => 'Runs apachectl configtest to validate without reloading.',
            'confirm' => 'Test the Apache configuration now?',
            'timeout' => 60,
            'script' => '(sudo -n apachectl configtest 2>&1 || apachectl configtest 2>&1)',
        ],

        // ---------------------------------------------------------------
        // Webserver lifecycle — start / stop / enable / disable for each
        // supported engine. The existing restart/reload/test triad is above.
        // `stop_*` and `disable_*` are flagged dangerous in the UI (red
        // confirm) because they cause site downtime / break boot-time start.
        // ---------------------------------------------------------------

        'start_nginx' => [
            'label' => 'Start NGINX',
            'description' => 'systemctl start nginx.',
            'confirm' => 'Start the NGINX service?',
            'timeout' => 60,
            'script' => '(sudo -n systemctl start nginx || systemctl start nginx) 2>&1',
            'rerun_probe_after_finish' => true,
        ],
        'stop_nginx' => [
            'label' => 'Stop NGINX',
            'description' => 'systemctl stop nginx. Sites served by nginx will be unavailable.',
            'confirm' => 'Stop NGINX? Sites served by nginx will be unavailable until you start it again.',
            'timeout' => 60,
            'script' => '(sudo -n systemctl stop nginx || systemctl stop nginx) 2>&1',
            'rerun_probe_after_finish' => true,
        ],
        'enable_nginx' => [
            'label' => 'Enable NGINX at boot',
            'description' => 'systemctl enable nginx.',
            'confirm' => 'Enable NGINX to start automatically at boot?',
            'timeout' => 60,
            'script' => '(sudo -n systemctl enable nginx || systemctl enable nginx) 2>&1',
        ],
        'disable_nginx' => [
            'label' => 'Disable NGINX at boot',
            'description' => 'systemctl disable nginx. Does not stop the running service.',
            'confirm' => 'Disable NGINX from starting at boot? The running service will keep running until stopped.',
            'timeout' => 60,
            'script' => '(sudo -n systemctl disable nginx || systemctl disable nginx) 2>&1',
        ],

        'start_caddy' => [
            'label' => 'Start Caddy',
            'description' => 'systemctl start caddy.',
            'confirm' => 'Start the Caddy service?',
            'timeout' => 60,
            'script' => '(sudo -n systemctl start caddy || systemctl start caddy) 2>&1',
            'rerun_probe_after_finish' => true,
        ],
        'stop_caddy' => [
            'label' => 'Stop Caddy',
            'description' => 'systemctl stop caddy. Sites served by Caddy will be unavailable.',
            'confirm' => 'Stop Caddy? Sites served by Caddy will be unavailable until you start it again.',
            'timeout' => 60,
            'script' => '(sudo -n systemctl stop caddy || systemctl stop caddy) 2>&1',
            'rerun_probe_after_finish' => true,
        ],
        'enable_caddy' => [
            'label' => 'Enable Caddy at boot',
            'description' => 'systemctl enable caddy.',
            'confirm' => 'Enable Caddy to start automatically at boot?',
            'timeout' => 60,
            'script' => '(sudo -n systemctl enable caddy || systemctl enable caddy) 2>&1',
        ],
        'disable_caddy' => [
            'label' => 'Disable Caddy at boot',
            'description' => 'systemctl disable caddy.',
            'confirm' => 'Disable Caddy from starting at boot? The running service will keep running until stopped.',
            'timeout' => 60,
            'script' => '(sudo -n systemctl disable caddy || systemctl disable caddy) 2>&1',
        ],

        'start_apache' => [
            'label' => 'Start Apache',
            'description' => 'systemctl start apache2.',
            'confirm' => 'Start the Apache service?',
            'timeout' => 60,
            'script' => '(sudo -n systemctl start apache2 || systemctl start apache2) 2>&1',
            'rerun_probe_after_finish' => true,
        ],
        'stop_apache' => [
            'label' => 'Stop Apache',
            'description' => 'systemctl stop apache2. Sites served by Apache will be unavailable.',
            'confirm' => 'Stop Apache? Sites served by Apache will be unavailable until you start it again.',
            'timeout' => 60,
            'script' => '(sudo -n systemctl stop apache2 || systemctl stop apache2) 2>&1',
            'rerun_probe_after_finish' => true,
        ],
        'enable_apache' => [
            'label' => 'Enable Apache at boot',
            'description' => 'systemctl enable apache2.',
            'confirm' => 'Enable Apache to start automatically at boot?',
            'timeout' => 60,
            'script' => '(sudo -n systemctl enable apache2 || systemctl enable apache2) 2>&1',
        ],
        'disable_apache' => [
            'label' => 'Disable Apache at boot',
            'description' => 'systemctl disable apache2.',
            'confirm' => 'Disable Apache from starting at boot? The running service will keep running until stopped.',
            'timeout' => 60,
            'script' => '(sudo -n systemctl disable apache2 || systemctl disable apache2) 2>&1',
        ],

        // OpenLiteSpeed service actions. Systemd unit is `lshttpd`; the
        // binary lives at /usr/local/lsws/bin/lshttpd. systemctl reload
        // dispatches to `lswsctrl reload` via the unit's ExecReload.
        'restart_openlitespeed' => [
            'label' => 'Restart OpenLiteSpeed',
            'description' => 'systemctl restart lshttpd. Sites may briefly show errors.',
            'confirm' => 'Restart OpenLiteSpeed now? Sites may briefly show errors.',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl restart lshttpd || systemctl restart lshttpd) 2>&1
else
  (sudo -n service lshttpd restart || service lshttpd restart) 2>&1
fi
BASH
        ],
        'reload_openlitespeed' => [
            'label' => 'Reload OpenLiteSpeed',
            'description' => 'Graceful reload via lswsctrl (no service interruption).',
            'confirm' => 'Reload OpenLiteSpeed configuration?',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl reload lshttpd || systemctl reload lshttpd) 2>&1
else
  (sudo -n /usr/local/lsws/bin/lswsctrl reload || /usr/local/lsws/bin/lswsctrl reload) 2>&1
fi
BASH
        ],
        'openlitespeed_test_config' => [
            'label' => 'Test OpenLiteSpeed config',
            'description' => 'Runs lshttpd -t to validate configuration without reloading.',
            'confirm' => 'Test the OpenLiteSpeed configuration now?',
            'timeout' => 60,
            'script' => '(sudo -n /usr/local/lsws/bin/lshttpd -t 2>&1 || /usr/local/lsws/bin/lshttpd -t 2>&1)',
        ],
        'start_openlitespeed' => [
            'label' => 'Start OpenLiteSpeed',
            'description' => 'systemctl start lshttpd.',
            'confirm' => 'Start the OpenLiteSpeed service?',
            'timeout' => 60,
            'script' => '(sudo -n systemctl start lshttpd || systemctl start lshttpd) 2>&1',
            'rerun_probe_after_finish' => true,
        ],
        'stop_openlitespeed' => [
            'label' => 'Stop OpenLiteSpeed',
            'description' => 'systemctl stop lshttpd. Sites served by OpenLiteSpeed will be unavailable.',
            'confirm' => 'Stop OpenLiteSpeed? Sites served by OpenLiteSpeed will be unavailable until you start it again.',
            'timeout' => 60,
            'script' => '(sudo -n systemctl stop lshttpd || systemctl stop lshttpd) 2>&1',
            'rerun_probe_after_finish' => true,
        ],
        'enable_openlitespeed' => [
            'label' => 'Enable OpenLiteSpeed at boot',
            'description' => 'systemctl enable lshttpd.',
            'confirm' => 'Enable OpenLiteSpeed to start automatically at boot?',
            'timeout' => 60,
            'script' => '(sudo -n systemctl enable lshttpd || systemctl enable lshttpd) 2>&1',
        ],
        'disable_openlitespeed' => [
            'label' => 'Disable OpenLiteSpeed at boot',
            'description' => 'systemctl disable lshttpd. Does not stop the running service.',
            'confirm' => 'Disable OpenLiteSpeed from starting at boot? The running service will keep running until stopped.',
            'timeout' => 60,
            'script' => '(sudo -n systemctl disable lshttpd || systemctl disable lshttpd) 2>&1',
        ],

        // Traefik service actions. Systemd unit is `traefik`; the binary
        // lives at /usr/local/bin/traefik. Traefik has no native reload
        // mechanism that re-reads /etc/traefik/traefik.yml (the static
        // config) — only dynamic configs are file-watched. A "reload"
        // action falls back to restart so changes to traefik.yml take
        // effect; dynamic /etc/traefik/dynamic/*.yml edits are picked up
        // automatically without any action.
        'restart_traefik' => [
            'label' => 'Restart Traefik',
            'description' => 'systemctl restart traefik. Sites may briefly show errors.',
            'confirm' => 'Restart Traefik now? Sites may briefly show errors.',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl restart traefik || systemctl restart traefik) 2>&1
else
  (sudo -n service traefik restart || service traefik restart) 2>&1
fi
BASH
        ],
        'reload_traefik' => [
            'label' => 'Reload Traefik',
            'description' => 'Traefik has no native reload for the static config; falls back to restart. Dynamic /etc/traefik/dynamic/*.yml edits are picked up automatically without any action.',
            'confirm' => 'Reload Traefik (will restart the daemon to pick up traefik.yml changes)?',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl restart traefik || systemctl restart traefik) 2>&1
else
  (sudo -n service traefik restart || service traefik restart) 2>&1
fi
BASH
        ],
        'traefik_test_config' => [
            'label' => 'Test Traefik backends',
            'description' => 'Validates the Caddy backend chain (where the actual web-serving lives) via `caddy validate`. Traefik itself has no parse-only mode.',
            'confirm' => 'Validate Traefik\'s Caddy backends?',
            'timeout' => 60,
            'script' => '(sudo -n caddy validate --config /etc/caddy/Caddyfile 2>&1 || caddy validate --config /etc/caddy/Caddyfile 2>&1)',
        ],
        'start_traefik' => [
            'label' => 'Start Traefik',
            'description' => 'systemctl start traefik.',
            'confirm' => 'Start the Traefik service?',
            'timeout' => 60,
            'script' => '(sudo -n systemctl start traefik || systemctl start traefik) 2>&1',
            'rerun_probe_after_finish' => true,
        ],
        'stop_traefik' => [
            'label' => 'Stop Traefik',
            'description' => 'systemctl stop traefik. Sites routed through Traefik will be unavailable.',
            'confirm' => 'Stop Traefik? Sites routed through Traefik will be unavailable until you start it again.',
            'timeout' => 60,
            'script' => '(sudo -n systemctl stop traefik || systemctl stop traefik) 2>&1',
            'rerun_probe_after_finish' => true,
        ],
        'enable_traefik' => [
            'label' => 'Enable Traefik at boot',
            'description' => 'systemctl enable traefik.',
            'confirm' => 'Enable Traefik to start automatically at boot?',
            'timeout' => 60,
            'script' => '(sudo -n systemctl enable traefik || systemctl enable traefik) 2>&1',
        ],
        'disable_traefik' => [
            'label' => 'Disable Traefik at boot',
            'description' => 'systemctl disable traefik. Does not stop the running service.',
            'confirm' => 'Disable Traefik from starting at boot? The running service will keep running until stopped.',
            'timeout' => 60,
            'script' => '(sudo -n systemctl disable traefik || systemctl disable traefik) 2>&1',
        ],

        // HAProxy service actions. `haproxy -c -f` is the native parse-only
        // validation; reload is graceful (no dropped connections).
        'restart_haproxy' => [
            'label' => 'Restart HAProxy',
            'description' => 'systemctl restart haproxy. Brief connection drop while the daemon restarts.',
            'confirm' => 'Restart HAProxy now? Sites may briefly show errors.',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl restart haproxy || systemctl restart haproxy) 2>&1
else
  (sudo -n service haproxy restart || service haproxy restart) 2>&1
fi
BASH
        ],
        'reload_haproxy' => [
            'label' => 'Reload HAProxy',
            'description' => 'Graceful reload of /etc/haproxy/haproxy.cfg (no dropped connections).',
            'confirm' => 'Reload HAProxy configuration?',
            'timeout' => 120,
            'script' => <<<'BASH'
if command -v systemctl >/dev/null 2>&1; then
  (sudo -n systemctl reload haproxy || systemctl reload haproxy) 2>&1
else
  (sudo -n service haproxy reload || service haproxy reload) 2>&1
fi
BASH
        ],
        'haproxy_test_config' => [
            'label' => 'Test HAProxy config',
            'description' => 'Runs `haproxy -c -f /etc/haproxy/haproxy.cfg` (parse-only).',
            'confirm' => 'Test the HAProxy configuration now?',
            'timeout' => 60,
            'script' => '(sudo -n haproxy -c -f /etc/haproxy/haproxy.cfg 2>&1 || haproxy -c -f /etc/haproxy/haproxy.cfg 2>&1)',
        ],
        'start_haproxy' => [
            'label' => 'Start HAProxy',
            'description' => 'systemctl start haproxy.',
            'confirm' => 'Start the HAProxy service?',
            'timeout' => 60,
            'script' => '(sudo -n systemctl start haproxy || systemctl start haproxy) 2>&1',
            'rerun_probe_after_finish' => true,
        ],
        'stop_haproxy' => [
            'label' => 'Stop HAProxy',
            'description' => 'systemctl stop haproxy. Sites routed through HAProxy will be unavailable.',
            'confirm' => 'Stop HAProxy? Sites routed through HAProxy will be unavailable until you start it again.',
            'timeout' => 60,
            'script' => '(sudo -n systemctl stop haproxy || systemctl stop haproxy) 2>&1',
            'rerun_probe_after_finish' => true,
        ],
        'enable_haproxy' => [
            'label' => 'Enable HAProxy at boot',
            'description' => 'systemctl enable haproxy.',
            'confirm' => 'Enable HAProxy to start automatically at boot?',
            'timeout' => 60,
            'script' => '(sudo -n systemctl enable haproxy || systemctl enable haproxy) 2>&1',
        ],
        'disable_haproxy' => [
            'label' => 'Disable HAProxy at boot',
            'description' => 'systemctl disable haproxy. Does not stop the running service.',
            'confirm' => 'Disable HAProxy from starting at boot? The running service will keep running until stopped.',
            'timeout' => 60,
            'script' => '(sudo -n systemctl disable haproxy || systemctl disable haproxy) 2>&1',
        ],

        // ---------------------------------------------------------------
        // Per-engine read-only CLI / diagnostic commands. Output lands in
        // the same flash/banner the existing test/reload actions use.
        // Nothing here mutates on-disk state except `caddy_fmt_overwrite`,
        // which is explicitly marked dangerous in the UI.
        // ---------------------------------------------------------------

        'caddy_fmt_preview' => [
            'label' => 'Format Caddyfile (preview)',
            'description' => 'Show how `caddy fmt` would reformat the Caddyfile — no file changes.',
            'confirm' => 'Show the formatted Caddyfile (preview only)?',
            'timeout' => 30,
            // `caddy fmt FILENAME` is a check that exits non-zero when the file
            // would change — it never prints the formatted version. The stdin
            // form (`caddy fmt -`) does emit the formatted output, which is
            // what we want here. Result: a unified diff against the live file,
            // or a confirmation message when nothing would change.
            'script' => <<<'BASH'
set -o pipefail
TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT
if ! (sudo -n cat /etc/caddy/Caddyfile 2>/dev/null || cat /etc/caddy/Caddyfile 2>/dev/null) \
   | (sudo -n caddy fmt - 2>/dev/null || caddy fmt - 2>/dev/null) > "$TMP"; then
  echo "Failed to read or format /etc/caddy/Caddyfile." >&2
  exit 1
fi
if diff -q /etc/caddy/Caddyfile "$TMP" >/dev/null 2>&1; then
  echo "Caddyfile is already formatted — no changes."
else
  echo "Caddyfile would be reformatted. Diff (live vs. formatted):"
  echo "---"
  diff -u /etc/caddy/Caddyfile "$TMP" 2>&1 | head -200
fi
BASH,
        ],
        'caddy_fmt_overwrite' => [
            'label' => 'Format Caddyfile (overwrite)',
            'description' => 'Run `caddy fmt --overwrite` — modifies /etc/caddy/Caddyfile in place.',
            'confirm' => 'Reformat and overwrite /etc/caddy/Caddyfile? The previous version will not be backed up.',
            'timeout' => 30,
            'script' => '(sudo -n caddy fmt --overwrite /etc/caddy/Caddyfile 2>&1 || caddy fmt --overwrite /etc/caddy/Caddyfile 2>&1); echo "---"; (sudo -n caddy validate --config /etc/caddy/Caddyfile 2>&1 || caddy validate --config /etc/caddy/Caddyfile 2>&1)',
        ],
        'caddy_adapt' => [
            'label' => 'Adapt Caddyfile → JSON',
            'description' => 'Show the JSON config Caddy generates from the current Caddyfile.',
            'confirm' => 'Show the adapted JSON config?',
            'timeout' => 30,
            'script' => '(sudo -n caddy adapt --config /etc/caddy/Caddyfile 2>&1 || caddy adapt --config /etc/caddy/Caddyfile 2>&1)',
        ],
        'caddy_environ' => [
            'label' => 'Show Caddy environment',
            'description' => 'Output of `caddy environ` — runtime/build env Caddy sees.',
            'confirm' => 'Show Caddy environment?',
            'timeout' => 15,
            'script' => '(sudo -n caddy environ 2>&1 || caddy environ 2>&1)',
        ],
        'caddy_version' => [
            'label' => 'Caddy version',
            'description' => '`caddy version` — installed binary version.',
            'confirm' => 'Show Caddy version?',
            'timeout' => 10,
            'script' => '(sudo -n caddy version 2>&1 || caddy version 2>&1)',
        ],
        'caddy_list_modules' => [
            'label' => 'List Caddy modules',
            'description' => '`caddy list-modules` — handlers, matchers, providers compiled in.',
            'confirm' => 'List Caddy modules?',
            'timeout' => 15,
            'script' => '(sudo -n caddy list-modules 2>&1 || caddy list-modules 2>&1)',
        ],

        'nginx_build_info' => [
            'label' => 'NGINX build info',
            'description' => '`nginx -V` — version, compile flags, configure args.',
            'confirm' => 'Show NGINX build info?',
            'timeout' => 10,
            'script' => '(sudo -n nginx -V 2>&1 || nginx -V 2>&1)',
        ],
        'nginx_effective_config' => [
            'label' => 'Effective NGINX config',
            'description' => '`nginx -T` — fully resolved config with all includes inlined.',
            'confirm' => 'Dump the effective NGINX config?',
            'timeout' => 30,
            'script' => '(sudo -n nginx -T 2>&1 || nginx -T 2>&1)',
        ],
        'nginx_reopen_logs' => [
            'label' => 'Reopen NGINX logs',
            'description' => '`nginx -s reopen` — closes + reopens log files. Use after log rotation.',
            'confirm' => 'Reopen NGINX log files?',
            'timeout' => 30,
            'script' => '(sudo -n nginx -s reopen 2>&1 || nginx -s reopen 2>&1); echo "Reopened."',
        ],

        'apache_modules' => [
            'label' => 'List Apache modules',
            'description' => '`apachectl -M` — modules currently loaded.',
            'confirm' => 'List Apache modules?',
            'timeout' => 15,
            'script' => '(sudo -n apachectl -M 2>&1 || apachectl -M 2>&1)',
        ],
        'apache_vhosts' => [
            'label' => 'Apache vhost dump',
            'description' => '`apachectl -S` — parsed virtual hosts and listening ports.',
            'confirm' => 'Dump Apache vhost configuration?',
            'timeout' => 15,
            'script' => '(sudo -n apachectl -S 2>&1 || apachectl -S 2>&1)',
        ],
        'apache_build_info' => [
            'label' => 'Apache build info',
            'description' => '`apachectl -V` — server version + compile-time settings.',
            'confirm' => 'Show Apache build info?',
            'timeout' => 10,
            'script' => '(sudo -n apachectl -V 2>&1 || apachectl -V 2>&1)',
        ],

        'openlitespeed_version' => [
            'label' => 'OpenLiteSpeed version',
            'description' => '`lshttpd -v` — server version + build identifier.',
            'confirm' => 'Show OpenLiteSpeed version?',
            'timeout' => 10,
            'script' => '/usr/local/lsws/bin/lshttpd -v 2>&1',
        ],
        'openlitespeed_modules' => [
            'label' => 'OpenLiteSpeed modules',
            'description' => 'List shared modules in /usr/local/lsws/modules. `lshttpd -M` itself refuses to run while the server is active.',
            'confirm' => 'List OpenLiteSpeed modules?',
            'timeout' => 10,
            'script' => 'ls -1 /usr/local/lsws/modules/*.so 2>/dev/null | sed -e "s|.*/||" -e "s|\\.so\\$||" | sort',
        ],
        'openlitespeed_status' => [
            'label' => 'OpenLiteSpeed status',
            'description' => '`lswsctrl status` — daemon state, PID, listener bindings.',
            'confirm' => 'Show OpenLiteSpeed status?',
            'timeout' => 15,
            'script' => '(sudo -n /usr/local/lsws/bin/lswsctrl status 2>&1 || /usr/local/lsws/bin/lswsctrl status 2>&1)',
        ],

        'traefik_version' => [
            'label' => 'Traefik version',
            'description' => '`traefik version` — build version + Go version + arch.',
            'confirm' => 'Show Traefik version?',
            'timeout' => 10,
            'script' => '(sudo -n /usr/local/bin/traefik version 2>&1 || /usr/local/bin/traefik version 2>&1)',
        ],
        'traefik_show_static_config' => [
            'label' => 'Show Traefik static config',
            'description' => 'Dump /etc/traefik/traefik.yml — entry points + provider settings.',
            'confirm' => 'Show Traefik\'s static config?',
            'timeout' => 10,
            'script' => '(sudo -n cat /etc/traefik/traefik.yml 2>&1 || cat /etc/traefik/traefik.yml 2>&1)',
        ],
        'traefik_list_dynamic_configs' => [
            'label' => 'List Traefik dynamic configs',
            'description' => 'List /etc/traefik/dynamic/*.yml — per-site routing files watched by Traefik.',
            'confirm' => 'List Traefik dynamic config files?',
            'timeout' => 10,
            'script' => '(sudo -n ls -la /etc/traefik/dynamic/ 2>&1 || ls -la /etc/traefik/dynamic/ 2>&1)',
        ],

        'haproxy_version' => [
            'label' => 'HAProxy version',
            'description' => '`haproxy -v` — build version + supported features.',
            'confirm' => 'Show HAProxy version?',
            'timeout' => 10,
            'script' => '(sudo -n haproxy -v 2>&1 || haproxy -v 2>&1)',
        ],
        'haproxy_show_config' => [
            'label' => 'Show HAProxy config',
            'description' => 'Dump /etc/haproxy/haproxy.cfg — full edge config with frontends and backends.',
            'confirm' => 'Show HAProxy config?',
            'timeout' => 10,
            'script' => '(sudo -n cat /etc/haproxy/haproxy.cfg 2>&1 || cat /etc/haproxy/haproxy.cfg 2>&1)',
        ],
        'haproxy_show_runtime_info' => [
            'label' => 'HAProxy runtime info',
            'description' => '`show info` over the admin socket — uptime, current sessions, process state.',
            'confirm' => 'Query HAProxy runtime info?',
            'timeout' => 10,
            'script' => '(sudo -n bash -c "echo show info | socat /run/haproxy/admin.sock stdio" 2>&1 || echo "(socat not installed or stats socket missing)")',
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

        // ─────────────────────────────────────────────────────────────────────
        // Operator-installable server toolchain — surfaced on Manage → Tools.
        // Each script is idempotent: a no-op when the tool is already present
        // at the expected version (early-exit echo), otherwise installs from
        // the upstream official source. The probe (`TOOLS_BEGIN` in
        // ServerInventoryProbeScript) populates the version pill on the Tools
        // tab; `rerun_probe_after_finish` refreshes that pill once the
        // queued install lands.
        // ─────────────────────────────────────────────────────────────────────
        // mise is installed during provisioning via the official apt repo
        // (see MiseInstallScriptBuilder::installLines). This action repairs /
        // force-refreshes the apt install — useful when the binary went missing,
        // the repo entry was clobbered, or the operator wants the latest from
        // the upstream apt repo without waiting on the next `apt upgrade` cycle.
        // It assumes the dply-managed apt source is still present (the standard
        // post-provision state); if not, the action fails loudly in the banner
        // and re-provisioning is the right next step.
        'install_mise' => [
            'label' => 'Reinstall mise',
            'description' => 'mise (https://mise.jdx.dev). Force-reinstalls the apt package the provisioner laid down — useful for repair / version refresh.',
            'confirm' => 'Reinstall mise from the apt repo? The current binary is replaced in place; runtime shims and per-user activation hooks are untouched.',
            'timeout' => 300,
            'script' => <<<'BASH'
set -e
if command -v mise >/dev/null 2>&1; then
  echo "Current: $(mise --version 2>&1 | head -n 1)"
fi
if [ ! -f /etc/apt/sources.list.d/mise.list ]; then
  echo "/etc/apt/sources.list.d/mise.list is missing — the dply mise apt source was not laid down at provisioning. Re-provision the server to restore it." >&2
  exit 1
fi
(sudo -n apt-get update -y || apt-get update -y) >/dev/null
(sudo -n apt-get install -y --reinstall --no-install-recommends mise || apt-get install -y --reinstall --no-install-recommends mise)
echo "Installed: $(mise --version 2>&1 | head -n 1)"
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'mise_prune' => [
            'label' => 'Prune unused runtimes',
            'description' => 'Remove mise runtime installs that are no longer referenced by any config or .tool-versions pin.',
            'confirm' => 'Prune unused mise runtime installs for the deploy user? Active global defaults and pinned versions are kept; orphaned installs are removed.',
            'timeout' => 300,
            'script' => <<<'BASH'
set -e
DEPLOY_USER="__DPLY_DEPLOY_USER__"
if [ -z "$DEPLOY_USER" ] || [ "$DEPLOY_USER" = "root" ]; then
  echo "No deploy user configured; cannot prune mise runtimes." >&2
  exit 1
fi
echo "[dply] pruning unused mise installs for $DEPLOY_USER"
sudo -u "$DEPLOY_USER" -H bash -lc 'mise prune -y'
echo "[dply] prune complete"
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'mise_reshim' => [
            'label' => 'Rebuild mise shims',
            'description' => 'Regenerate shims under ~/.local/share/mise/shims after manual changes or a broken PATH.',
            'confirm' => 'Rebuild mise shims for the deploy user? Safe repair step — does not change installed versions.',
            'timeout' => 120,
            'script' => <<<'BASH'
set -e
DEPLOY_USER="__DPLY_DEPLOY_USER__"
if [ -z "$DEPLOY_USER" ] || [ "$DEPLOY_USER" = "root" ]; then
  echo "No deploy user configured; cannot rebuild mise shims." >&2
  exit 1
fi
echo "[dply] rebuilding mise shims for $DEPLOY_USER"
sudo -u "$DEPLOY_USER" -H bash -lc 'mise reshim'
echo "[dply] reshim complete"
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'install_docker' => [
            'label' => 'Install Docker service',
            'description' => 'Docker Engine via the upstream get.docker.com convenience script.',
            'confirm' => 'Install Docker Engine on this server? The official get.docker.com convenience script will run with sudo; it adds the Docker apt repo and installs docker-ce, docker-ce-cli, and containerd.',
            'timeout' => 600,
            'script' => <<<'BASH'
set -e
if command -v docker >/dev/null 2>&1; then
  echo "Docker already installed: $(docker --version 2>&1 | head -n 1)"
  echo "Skipping reinstall — uninstall first if you need a clean rebuild."
  exit 0
fi
# Official Docker convenience installer — pinned upstream:
# https://docs.docker.com/engine/install/ubuntu/#install-using-the-convenience-script
curl --silent --show-error --fail --location --output /tmp/get-docker.sh https://get.docker.com
sudo -n sh /tmp/get-docker.sh
rm -f /tmp/get-docker.sh
sudo -n systemctl enable --now docker 2>/dev/null || true
docker --version 2>&1 | head -n 1
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'repair_docker' => [
            'label' => 'Upgrade Docker Engine',
            'description' => 'Refresh docker-ce / containerd packages via apt when apt lists an upgrade.',
            'confirm' => 'Upgrade Docker Engine via apt? Installs the latest available docker-ce, docker-ce-cli, and containerd.io packages already on this server.',
            'timeout' => 600,
            'script' => <<<'BASH'
set -e
if command -v docker >/dev/null 2>&1; then
  echo "Current: $(docker --version 2>&1 | head -n 1)"
fi
(sudo -n apt-get update -y || apt-get update -y) >/dev/null
pkgs=""
for p in docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin docker.io; do
  if apt-cache show "$p" >/dev/null 2>&1; then
    pkgs="$pkgs $p"
  fi
done
if [ -z "$pkgs" ]; then
  echo "No docker apt packages found to upgrade." >&2
  exit 1
fi
# shellcheck disable=SC2086
(sudo -n apt-get install -y --only-upgrade $pkgs || apt-get install -y --only-upgrade $pkgs)
echo "Installed: $(docker --version 2>&1 | head -n 1)"
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'docker_container_start' => [
            'label' => 'Start container',
            'description' => 'docker start for one container ID or name.',
            'confirm' => 'Start this container on the server?',
            'timeout' => 120,
            'script' => <<<'BASH'
set -e
CID=__DPLY_CONTAINER_ID__
if [ -z "$CID" ]; then
  echo "Missing container id." >&2
  exit 1
fi
sudo -n docker start "$CID"
docker ps --filter "id=$CID" --filter "name=$CID" --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}'
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'docker_container_stop' => [
            'label' => 'Stop container',
            'description' => 'docker stop for one container ID or name.',
            'confirm' => 'Stop this container? Running processes inside will be sent SIGTERM.',
            'timeout' => 180,
            'script' => <<<'BASH'
set -e
CID=__DPLY_CONTAINER_ID__
if [ -z "$CID" ]; then
  echo "Missing container id." >&2
  exit 1
fi
sudo -n docker stop "$CID"
docker ps -a --filter "id=$CID" --filter "name=$CID" --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}'
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'docker_container_restart' => [
            'label' => 'Restart container',
            'description' => 'docker restart for one container ID or name.',
            'confirm' => 'Restart this container? It will stop then start again.',
            'timeout' => 180,
            'script' => <<<'BASH'
set -e
CID=__DPLY_CONTAINER_ID__
if [ -z "$CID" ]; then
  echo "Missing container id." >&2
  exit 1
fi
sudo -n docker restart "$CID"
docker ps --filter "id=$CID" --filter "name=$CID" --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}'
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'docker_container_rm' => [
            'label' => 'Remove container',
            'description' => 'docker rm -f for one stopped or running container.',
            'confirm' => 'Remove this container from the server? This deletes the container record — not the image.',
            'timeout' => 180,
            'script' => <<<'BASH'
set -e
CID=__DPLY_CONTAINER_ID__
if [ -z "$CID" ]; then
  echo "Missing container id." >&2
  exit 1
fi
sudo -n docker rm -f "$CID"
echo "Removed container $CID"
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'docker_image_pull' => [
            'label' => 'Pull image',
            'description' => 'docker pull for a repository:tag or digest reference.',
            'confirm' => 'Pull this image onto the server?',
            'timeout' => 900,
            'script' => <<<'BASH'
set -e
REF=__DPLY_IMAGE_REF__
if [ -z "$REF" ]; then
  echo "Missing image reference." >&2
  exit 1
fi
sudo -n docker pull "$REF"
docker images --filter "reference=$REF" --format 'table {{.Repository}}\t{{.Tag}}\t{{.ID}}\t{{.Size}}'
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'docker_image_rm' => [
            'label' => 'Remove image',
            'description' => 'docker rmi for one image ID or repository:tag.',
            'confirm' => 'Remove this image from the server? Containers using it must be removed first.',
            'timeout' => 300,
            'script' => <<<'BASH'
set -e
REF=__DPLY_IMAGE_REF__
if [ -z "$REF" ]; then
  echo "Missing image reference." >&2
  exit 1
fi
sudo -n docker rmi "$REF"
docker system df
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'docker_image_prune' => [
            'label' => 'Prune dangling images',
            'description' => 'docker image prune -f — removes dangling images only.',
            'confirm' => 'Prune dangling Docker images on this server? Tagged images in use are kept.',
            'timeout' => 300,
            'script' => <<<'BASH'
set -e
if ! command -v docker >/dev/null 2>&1; then
  echo "docker not installed." >&2
  exit 1
fi
sudo -n docker image prune -f
docker system df
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'docker_volume_prune' => [
            'label' => 'Prune unused volumes',
            'description' => 'docker volume prune -f — removes volumes not used by any container.',
            'confirm' => 'Prune unused Docker volumes? Data in unused volumes will be deleted permanently.',
            'timeout' => 300,
            'script' => <<<'BASH'
set -e
sudo -n docker volume prune -f
docker system df
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'docker_system_prune' => [
            'label' => 'System prune',
            'description' => 'docker system prune -af — removes stopped containers, unused networks, dangling images, and build cache.',
            'confirm' => 'Run Docker system prune on this server? Stopped containers, unused networks, dangling images, and build cache will be removed. Named volumes are kept unless you prune them separately.',
            'timeout' => 600,
            'script' => <<<'BASH'
set -e
sudo -n docker system prune -af
docker system df
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'install_wp_cli' => [
            'label' => 'Install WordPress CLI',
            'description' => 'wp-cli (https://wp-cli.org) — command-line interface for managing WordPress sites.',
            'confirm' => 'Install wp-cli at /usr/local/bin/wp? Pulls the latest phar from wp-cli.org.',
            'timeout' => 120,
            'script' => <<<'BASH'
set -e
if command -v wp >/dev/null 2>&1; then
  echo "wp-cli already installed: $(wp --version 2>&1 | head -n 1)"
  exit 0
fi
# Mirrors ScaffoldPrerequisites::ensureWpCli — the scaffold pipeline calls
# the same install path automatically when scaffolding a WordPress site.
curl --silent --show-error --location --output /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x /tmp/wp-cli.phar
sudo -n mv /tmp/wp-cli.phar /usr/local/bin/wp
wp --info --allow-root 2>&1 | head -n 10
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'update_wp_cli' => [
            'label' => 'Update wp-cli',
            'description' => 'Pull the latest wp-cli phar from wp-cli.org and replace /usr/local/bin/wp.',
            'confirm' => 'Update wp-cli to the latest release? The current phar at /usr/local/bin/wp is replaced in place.',
            'timeout' => 120,
            'script' => <<<'BASH'
set -e
if command -v wp >/dev/null 2>&1; then
  echo "Current: $(wp --version 2>&1 | head -n 1)"
fi
curl --silent --show-error --location --output /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x /tmp/wp-cli.phar
sudo -n mv /tmp/wp-cli.phar /usr/local/bin/wp
echo "Installed: $(wp --version 2>&1 | head -n 1)"
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'install_composer' => [
            'label' => 'Install Composer',
            'description' => 'Composer (https://getcomposer.org) — PHP dependency manager.',
            'confirm' => 'Install Composer at /usr/local/bin/composer? Uses the official getcomposer.org installer.',
            'timeout' => 180,
            'script' => <<<'BASH'
set -e
if command -v composer >/dev/null 2>&1; then
  echo "Composer already installed: $(composer --version 2>&1 | head -n 1)"
  echo "Skipping reinstall — remove /usr/local/bin/composer first if you need a clean rebuild."
  exit 0
fi
if ! command -v php >/dev/null 2>&1; then
  echo "PHP is not installed on this server — install PHP before Composer." >&2
  exit 1
fi
curl --silent --show-error --fail --location --output /tmp/composer-setup.php https://getcomposer.org/installer
php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm -f /tmp/composer-setup.php
composer --version 2>&1 | head -n 1
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'install_git' => [
            'label' => 'Install Git',
            'description' => 'Git version control CLI via apt.',
            'confirm' => 'Install Git on this server? Uses apt to install the git package.',
            'timeout' => 180,
            'script' => <<<'BASH'
set -e
if command -v git >/dev/null 2>&1; then
  echo "Git already installed: $(git --version 2>&1 | head -n 1)"
  exit 0
fi
apt-get update -y
apt-get install -y --no-install-recommends git
git --version 2>&1 | head -n 1
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'repair_git' => [
            'label' => 'Upgrade Git',
            'description' => 'Refresh the apt git package — picks up security releases from the distro repo.',
            'confirm' => 'Upgrade Git via apt? Installs the latest available git package from apt; safe on preinstalled servers.',
            'timeout' => 180,
            'script' => <<<'BASH'
set -e
if command -v git >/dev/null 2>&1; then
  echo "Current: $(git --version 2>&1 | head -n 1)"
fi
(sudo -n apt-get update -y || apt-get update -y) >/dev/null
(sudo -n apt-get install -y --no-install-recommends git || apt-get install -y --no-install-recommends git)
echo "Installed: $(git --version 2>&1 | head -n 1)"
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'install_redis_cli' => [
            'label' => 'Install redis-cli',
            'description' => 'redis-tools / redis-server client package for cache inspection.',
            'confirm' => 'Install redis-cli on this server? Installs redis-tools (and redis-server if needed) via apt.',
            'timeout' => 300,
            'script' => <<<'BASH'
set -e
if command -v redis-cli >/dev/null 2>&1; then
  echo "redis-cli already installed: $(redis-cli --version 2>&1 | head -n 1)"
  exit 0
fi
if command -v valkey-cli >/dev/null 2>&1; then
  echo "valkey-cli already installed: $(valkey-cli --version 2>&1 | head -n 1)"
  exit 0
fi
apt-get update -y
if apt-get install -y --no-install-recommends redis-tools; then
  :
elif apt-get install -y --no-install-recommends redis-server; then
  :
else
  apt-get install -y --no-install-recommends valkey-tools || apt-get install -y --no-install-recommends valkey-server
fi
(command -v redis-cli >/dev/null 2>&1 && redis-cli --version 2>&1 | head -n 1) \
  || (command -v valkey-cli >/dev/null 2>&1 && valkey-cli --version 2>&1 | head -n 1)
BASH,
            'rerun_probe_after_finish' => true,
        ],

        'repair_redis_cli' => [
            'label' => 'Upgrade redis-cli',
            'description' => 'Refresh redis-tools or valkey-tools via apt when a newer release is available.',
            'confirm' => 'Upgrade redis-cli via apt? Installs the latest redis-tools or valkey-tools package from apt.',
            'timeout' => 300,
            'script' => <<<'BASH'
set -e
if command -v redis-cli >/dev/null 2>&1; then
  echo "Current: $(redis-cli --version 2>&1 | head -n 1)"
elif command -v valkey-cli >/dev/null 2>&1; then
  echo "Current: $(valkey-cli --version 2>&1 | head -n 1)"
fi
(sudo -n apt-get update -y || apt-get update -y) >/dev/null
if apt-cache show redis-tools >/dev/null 2>&1; then
  (sudo -n apt-get install -y --no-install-recommends redis-tools || apt-get install -y --no-install-recommends redis-tools)
elif apt-cache show valkey-tools >/dev/null 2>&1; then
  (sudo -n apt-get install -y --no-install-recommends valkey-tools || apt-get install -y --no-install-recommends valkey-tools)
else
  (sudo -n apt-get install -y --no-install-recommends redis-tools || apt-get install -y --no-install-recommends redis-tools) \
    || (sudo -n apt-get install -y --no-install-recommends valkey-tools || apt-get install -y --no-install-recommends valkey-tools)
fi
(command -v redis-cli >/dev/null 2>&1 && echo "Installed: $(redis-cli --version 2>&1 | head -n 1)") \
  || (command -v valkey-cli >/dev/null 2>&1 && echo "Installed: $(valkey-cli --version 2>&1 | head -n 1)")
BASH,
            'rerun_probe_after_finish' => true,
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

    /*
    | mise-managed language runtimes shown on Manage → Tools. Keys must match
    | mise plugin names (`mise ls-remote <key>`). PHP is intentionally omitted
    | (ondrej/php apt path).
    */
    'mise_runtimes' => [
        'node' => [
            'label' => 'Node.js',
            'placeholder' => '20.16.0',
            'hint' => 'Numeric major or full semver (e.g. 20, 20.16.0, lts).',
        ],
        'python' => [
            'label' => 'Python',
            'placeholder' => '3.12.5',
            'hint' => 'Major.minor or full version (e.g. 3.12, 3.12.5).',
        ],
        'ruby' => [
            'label' => 'Ruby',
            'placeholder' => '3.3.4',
            'hint' => 'Major.minor.patch (e.g. 3.3.4). Pre-builds take 30–60s on small droplets.',
        ],
        'go' => [
            'label' => 'Go',
            'placeholder' => '1.23.0',
            'hint' => 'Major.minor or full version (e.g. 1.23, 1.23.0).',
        ],
        'bun' => [
            'label' => 'Bun',
            'placeholder' => '1.2.0',
            'hint' => 'Full semver (e.g. 1.2.0). JavaScript runtime + toolkit.',
        ],
        'deno' => [
            'label' => 'Deno',
            'placeholder' => '2.1.0',
            'hint' => 'Full semver (e.g. 2.1.0). Secure JS/TS runtime.',
        ],
        'java' => [
            'label' => 'Java',
            'placeholder' => '21.0.2',
            'hint' => 'Temurin/OpenJDK via mise (e.g. 21, 21.0.2).',
        ],
    ],

    /*
    | ServerInventoryProbeScript and optionally a service_actions install key.
    | card=hero renders the full-width mise panel; card=generic renders a grid card.
    */
    'tool_catalog' => [
        'mise' => [
            'slug' => 'mise',
            'label' => 'mise (dev tool version manager)',
            'description' => 'Installs Node, Python, Ruby, Go, Bun, Deno, Java per project via .tool-versions / .mise.toml. dply installs mise from the official apt repo during provisioning — this surface is here for repair / version refresh, not first-install.',
            'docs_url' => 'https://mise.jdx.dev',
            'icon' => 'heroicon-o-cube-transparent',
            'probe_key' => 'mise',
            'action_key' => 'install_mise',
            'preinstalled' => true,
            'card' => 'hero',
        ],
        'composer' => [
            'slug' => 'composer',
            'label' => 'Composer',
            'description' => 'PHP dependency manager for Laravel and other PHP projects. Installed during provisioning when PHP is present; use Install when the binary is missing.',
            'docs_url' => 'https://getcomposer.org',
            'icon' => 'heroicon-o-code-bracket-square',
            'probe_key' => 'composer',
            'action_key' => 'install_composer',
            'action_when' => 'missing',
            'requires_php' => true,
            'preinstalled' => true,
            'card' => 'generic',
        ],
        'git' => [
            'slug' => 'git',
            'label' => 'Git',
            'description' => 'Version control CLI — preinstalled on dply servers. Upgrade below when apt has a newer release; connect GitHub/GitLab/Bitbucket under Source control for deploy credentials.',
            'docs_url' => 'https://git-scm.com',
            'icon' => 'heroicon-o-code-bracket',
            'probe_key' => 'git',
            'action_key' => 'install_git',
            'action_when' => 'missing',
            'present_action_key' => 'repair_git',
            'show_source_control_link' => true,
            'preinstalled' => true,
            'card' => 'generic',
        ],
        'docker' => [
            'slug' => 'docker',
            'label' => 'Docker Engine',
            'description' => 'Container runtime + CLI. Install when missing, upgrade when apt has docker-ce releases, and open Docker to list containers and images.',
            'docs_url' => 'https://docs.docker.com/engine/install/',
            'icon' => 'heroicon-o-square-3-stack-3d',
            'probe_key' => 'docker',
            'action_key' => 'install_docker',
            'action_when' => 'missing',
            'present_action_key' => 'repair_docker',
            'show_docker_workspace_link' => true,
            'card' => 'generic',
        ],
        'wp_cli' => [
            'slug' => 'wp_cli',
            'label' => 'wp-cli (WordPress CLI)',
            'description' => 'Command-line interface for managing WordPress sites — plugins, themes, users, search-replace. dply\'s WordPress scaffold installs this automatically on first scaffold; use Update below to pull the latest phar when wp is already present.',
            'docs_url' => 'https://wp-cli.org',
            'icon' => 'heroicon-o-code-bracket',
            'probe_key' => 'wp_cli',
            'action_key' => 'install_wp_cli',
            'action_when' => 'missing',
            'present_action_key' => 'update_wp_cli',
            'show_run_link' => true,
            'card' => 'generic',
        ],
        'redis_cli' => [
            'slug' => 'redis_cli',
            'label' => 'redis-cli',
            'description' => 'Redis wire-protocol CLI for cache inspection. Installed with Redis/Valkey during provisioning; use Install when the CLI is missing and Upgrade when apt has a newer redis-tools or valkey-tools release. Open Caches for stats, key browser, and REPL.',
            'docs_url' => 'https://redis.io/docs/latest/develop/tools/cli/',
            'icon' => 'heroicon-o-circle-stack',
            'probe_key' => 'redis_cli',
            'action_key' => 'install_redis_cli',
            'action_when' => 'missing',
            'present_action_key' => 'repair_redis_cli',
            'show_when_redis_relevant' => true,
            'preinstalled' => true,
            'card' => 'generic',
        ],
    ],

    /**
     * Server "Manage" workspace sub-pages (URL segment after /manage/). Order matches the tab bar.
     */
    /**
     * Unified configuration editor catalog — grouped file discovery for
     * {@see ServerConfigFileCatalog}. Webserver group
     * uses live SSH discovery via {@see RemoteWebserverConfigService}; other
     * groups list static entries and optional globs (always allowlist-checked).
     */
    'config_file_catalog' => [
        'webserver' => [
            'label' => 'Webserver',
            'discover_engines' => true,
            'file_type' => 'nginx',
        ],
        'php' => [
            'label' => 'PHP',
            'file_type' => 'ini',
            'entries' => [
                ['label' => 'php.ini (CLI)', 'path' => '/etc/php/8.3/cli/php.ini', 'file_type' => 'ini'],
                ['label' => 'php.ini (FPM)', 'path' => '/etc/php/8.3/fpm/php.ini', 'file_type' => 'ini'],
                ['label' => 'www.conf (FPM pool)', 'path' => '/etc/php/8.3/fpm/pool.d/www.conf', 'file_type' => 'ini'],
            ],
            'globs' => [
                '/etc/php/*/fpm/php.ini',
                '/etc/php/*/fpm/pool.d/*.conf',
            ],
        ],
        'redis_db' => [
            'label' => 'Redis & DB',
            'file_type' => 'conf',
            'entries' => [
                ['label' => 'redis.conf', 'path' => '/etc/redis/redis.conf'],
                ['label' => 'my.cnf', 'path' => '/etc/mysql/my.cnf'],
            ],
            'globs' => [
                '/etc/mysql/mariadb.conf.d/*.cnf',
            ],
        ],
        'system' => [
            'label' => 'System',
            'file_type' => 'conf',
            'entries' => [
                ['label' => 'sshd_config', 'path' => '/etc/ssh/sshd_config'],
                ['label' => 'unattended-upgrades', 'path' => '/etc/apt/apt.conf.d/50unattended-upgrades'],
                ['label' => '20auto-upgrades', 'path' => '/etc/apt/apt.conf.d/20auto-upgrades'],
            ],
        ],
        'supervisor' => [
            'label' => 'Supervisor',
            'file_type' => 'ini',
            'entries' => [
                ['label' => 'supervisord.conf', 'path' => '/etc/supervisor/supervisord.conf'],
            ],
            'globs' => [
                '/etc/supervisor/conf.d/*.conf',
            ],
        ],
    ],

    /**
     * Validation hooks for non-webserver config paths. Longest prefix match wins.
     */
    'config_validation_hooks' => [
        'exact' => [
            '/etc/ssh/sshd_config' => [
                'validate' => '(sudo -n sshd -t 2>&1 || sshd -t 2>&1)',
                'success_contains' => [],
                'failure_contains' => ['fatal', 'missing', 'bad configuration'],
                'validate_timeout' => 30,
            ],
            '/etc/redis/redis.conf' => [
                'validate' => '(sudo -n redis-server /etc/redis/redis.conf --test-memory 1 2>&1 || redis-server /etc/redis/redis.conf --test-memory 1 2>&1)',
                'success_contains' => [],
                'failure_contains' => ['err', 'fatal', 'failed'],
                'validate_timeout' => 30,
            ],
        ],
        'prefixes' => [
            '/etc/php/' => [
                'validate' => '(sudo -n php-fpm8.3 -t 2>&1 || php-fpm8.3 -t 2>&1 || php-fpm -t 2>&1)',
                'success_contains' => ['successful', 'test is successful'],
                'failure_contains' => ['error', 'failed', 'emerg'],
                'validate_timeout' => 45,
            ],
            '/etc/supervisor/' => [
                'validate' => '(sudo -n supervisorctl reread 2>&1 && sudo -n supervisorctl status 2>&1 || supervisorctl reread 2>&1)',
                'success_contains' => [],
                'failure_contains' => ['error', 'no such file', 'invalid'],
                'validate_timeout' => 30,
            ],
        ],
    ],

    /**
     * Static autocomplete snippets keyed by file type for CodeMirror v1.
     *
     * @var array<string, list<array{label: string, insert: string, type?: string, detail?: string}>>
     */
    'config_autocomplete_snippets' => [
        'nginx' => [
            ['label' => 'server block', 'insert' => "server {\n    listen 80;\n    server_name example.com;\n    root /var/www/html;\n\n    location / {\n        try_files \$uri \$uri/ =404;\n    }\n}\n", 'detail' => 'Basic HTTP server'],
            ['label' => 'upstream', 'insert' => "upstream backend {\n    server 127.0.0.1:8080;\n}\n", 'detail' => 'Upstream group'],
        ],
        'ini' => [
            ['label' => 'memory_limit', 'insert' => "memory_limit = 256M\n", 'detail' => 'PHP memory limit'],
            ['label' => 'supervisor program', 'insert' => "[program:example]\ncommand=/usr/bin/example\nautostart=true\nautorestart=true\n", 'detail' => 'Supervisor program block'],
        ],
        'conf' => [
            ['label' => 'bind redis', 'insert' => "bind 127.0.0.1 ::1\n", 'detail' => 'Local-only Redis bind'],
        ],
        'default' => [],
    ],

    'workspace_tabs' => [
        'overview' => ['label' => 'Overview', 'icon' => 'squares-2x2'],
        // 'services' sub-tab retired: it duplicated the standalone /servers/{id}/services
        // page (workspace top-nav). Manage stays focused on host-level admin (data,
        // updates, configuration, danger); systemd units + listening ports live on
        // the Services page and the Firewall page respectively.
        // 'web' sub-tab retired: promoted to /servers/{id}/webserver as a peer of
        // PHP / Caches / Cron — mount() redirects /manage/web for back-compat.
        // 'data' sub-tab retired: the live Redis INFO snapshot + Show Redis INFO
        // action moved to the Caches workspace (redis Stats subtab); the MySQL
        // processlist action + DB connection-hints form moved to the Databases
        // workspace (MySQL Info subtab). mount() redirects /manage/data for back-compat.
        // When workspace.patch_advisor is on, the Updates tab is hidden and
        // /manage/updates redirects to Patches; this entry remains for orgs
        // with the feature disabled.
        'updates' => ['label' => 'Updates', 'icon' => 'arrow-path'],
        'tools' => ['label' => 'Tools', 'icon' => 'wrench-screwdriver'],
        'configuration' => ['label' => 'Configuration', 'icon' => 'document-text'],
        'danger' => ['label' => 'Danger', 'icon' => 'exclamation-triangle'],
    ],

    /**
     * When true, Manage auto-queues an inventory/probe refresh on load (and while
     * provisioning finishes) so Overview populates without a manual Refresh state click.
     */
    'inventory_probe_refresh_on_load' => (bool) env('SERVER_MANAGE_INVENTORY_PROBE_REFRESH_ON_LOAD', true),

    /** wire:poll interval (seconds) while SSH is not ready or probe meta is empty. */
    'inventory_probe_poll_seconds' => max(3, (int) env('SERVER_MANAGE_INVENTORY_PROBE_POLL_SECONDS', 5)),

];
