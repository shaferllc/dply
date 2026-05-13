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
        // 'services' sub-tab retired: it duplicated the standalone /servers/{id}/services
        // page (workspace top-nav). Manage stays focused on host-level admin (data,
        // updates, configuration, danger); systemd units + listening ports live on
        // the Services page and the Firewall page respectively.
        // 'web' sub-tab retired: promoted to /servers/{id}/webserver as a peer of
        // PHP / Caches / Cron — mount() redirects /manage/web for back-compat.
        'data' => ['label' => 'Data', 'icon' => 'circle-stack'],
        'updates' => ['label' => 'Updates', 'icon' => 'arrow-path'],
        'configuration' => ['label' => 'Configuration', 'icon' => 'document-text'],
        'danger' => ['label' => 'Danger', 'icon' => 'exclamation-triangle'],
    ],

];
