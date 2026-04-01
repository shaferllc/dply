<?php

/**
 * Allowlisted apt-based installs over SSH (same execution path as Manage → service actions).
 * Scripts must not interpolate user input.
 *
 * {@see ServerSystemdServicesCatalog} merges `systemd_units` with per-server custom units
 * (stored in server meta) for the Services workspace inventory.
 */
return [

    /**
     * Try root SSH first for privileged service and supervisor actions, then fall back to the
     * configured SSH user when needed.
     */
    'use_root_ssh' => (bool) env('SERVER_SERVICES_USE_ROOT_SSH', true),

    'fallback_to_deploy_user_ssh' => (bool) env('SERVER_SERVICES_FALLBACK_TO_DEPLOY_SSH', true),

    /**
     * Default systemd units shown on Services (inventory + actions). PHP-FPM uses
     * `default_php_version` from server meta when resolving `php-fpm`.
     *
     * @var list<array{unit: string, version_package?: string|null}>
     */
    'systemd_units' => [
        ['unit' => 'nginx', 'version_package' => 'nginx'],
        ['unit' => 'fail2ban', 'version_package' => 'fail2ban'],
        ['unit' => 'memcached', 'version_package' => 'memcached'],
        ['unit' => 'redis-server', 'version_package' => 'redis-server'],
        ['unit' => 'postgresql', 'version_package' => 'postgresql'],
        ['unit' => 'ssh', 'version_package' => 'openssh-server'],
        ['unit' => 'ufw', 'version_package' => 'ufw'],
        ['unit' => 'supervisor', 'version_package' => 'supervisor'],
        ['unit' => 'php-fpm', 'version_package' => null],
    ],

    /**
     * Optional dpkg package name when it differs from the unit basename (e.g. openssh-server → ssh.service).
     * Keys are unit basenames without .service (e.g. ssh, nginx).
     *
     * @var array<string, string>
     */
    'systemd_unit_version_packages' => [
        'ssh' => 'openssh-server',
    ],

    /**
     * Unit basenames (with or without .service) that get an inline “Disable on boot” button on
     * Services in addition to the ⋯ menu. Use for optional daemons (cache, DB) — not ssh, nginx,
     * php-fpm, or supervisor unless you accept the operational risk.
     *
     * @var list<string>
     */
    'systemd_units_inline_disable_at_boot' => [
        'memcached',
        'redis-server',
        'postgresql',
    ],

    /** Seconds for single systemd start/stop/restart over SSH. */
    'systemd_action_timeout' => (int) env('SERVER_SERVICES_SYSTEMD_ACTION_TIMEOUT', 180),

    /** Inventory lists running .service units (see {@see ServerSystemdServicesCatalog::buildInventoryScript}). */
    'systemd_inventory_max_units' => (int) env('SERVER_SERVICES_SYSTEMD_INVENTORY_MAX_UNITS', 500),

    /** SSH timeout for full running-service inventory (many units). */
    'systemd_inventory_timeout' => (int) env('SERVER_SERVICES_SYSTEMD_INVENTORY_TIMEOUT', 300),

    /** When true, queue a fresh inventory SSH check when the Services tab loads (if the user may update the server). */
    'systemd_inventory_refresh_on_load' => (bool) env('SERVER_SERVICES_SYSTEMD_INVENTORY_REFRESH_ON_LOAD', true),

    /**
     * Skip auto refresh if the last snapshot is newer than this many seconds (avoids duplicate work on quick revisits).
     */
    'systemd_inventory_skip_auto_refresh_if_newer_than_seconds' => (int) env('SERVER_SERVICES_SYSTEMD_INVENTORY_SKIP_AUTO_IF_NEWER_THAN', 45),

    /** Rolling audit rows per server in {@see ServerSystemdServiceAuditEvent}. */
    'systemd_services_activity_max_events' => (int) env('SERVER_SERVICES_SYSTEMD_ACTIVITY_MAX_EVENTS', 75),

    /** Master switch for {@see SyncServerSystemdServicesJob}. */
    'systemd_inventory_job_enabled' => (bool) env('SERVER_SYSTEMD_INVENTORY_JOB_ENABLED', true),

    /** Dispatch {@see SyncServerSystemdServicesJob} for all ready servers on the scheduler. */
    'systemd_inventory_schedule_enabled' => (bool) env('SERVER_SYSTEMD_INVENTORY_SCHEDULE_ENABLED', true),

    /** Optional queue name for systemd inventory jobs (Horizon must list it when set). */
    'sync_queue' => env('SERVER_SYSTEMD_SYNC_QUEUE'),

    /*
    | Merged with Organization.services_preferences via array_replace_recursive.
    | systemd_status_only_units here are org-wide defaults; org JSON can add more basenames.
    */
    'organization_defaults' => [
        'deployer_systemd_actions_enabled' => (bool) env('SERVER_SERVICES_DEPLOYER_SYSTEMD_ACTIONS', false),
        'systemd_notifications_digest' => env('SERVER_SERVICES_SYSTEMD_NOTIFICATIONS_DIGEST', 'immediate'),
        'systemd_status_only_units' => [],
    ],

    /** Global unit basenames (no .service) that cannot be mutated via Services (org list is additive). */
    'systemd_status_only_units' => [],

    /** When false, {@see FlushServerSystemdNotificationDigestCommand} is not scheduled. */
    'systemd_digest_flush_enabled' => (bool) env('SERVER_SERVICES_SYSTEMD_DIGEST_FLUSH_ENABLED', true),

    'install_actions' => [
        'install_monitoring_prerequisites' => [
            'label' => 'Python 3 for monitoring',
            'description' => 'python3-minimal (apt) plus ~/.dply/bin/server-metrics-snapshot.py. Required for Dply to sample CPU, memory, and disk over SSH.',
            'confirm' => 'Install Python 3 and deploy the metrics script on this server?',
            'timeout' => 600,
            /*
             * Full script is merged in AppServiceProvider from resources/server-scripts/server-metrics-snapshot.py
             * (apt install + base64 deploy). Fallback below if that file is missing.
             */
            'script' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
if command -v python3 >/dev/null 2>&1; then
  echo "python3 is already installed."
  python3 --version
  exit 0
fi
(sudo -n apt-get update && sudo -n apt-get install -y python3-minimal) 2>&1 \
  || (apt-get update && apt-get install -y python3-minimal) 2>&1
BASH
        ],
        'install_redis' => [
            'label' => 'Install Redis',
            'description' => 'redis-server (Debian/Ubuntu via apt).',
            'confirm' => 'Install Redis with apt on this server? Existing configuration is preserved when possible.',
            'timeout' => 900,
            'script' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
(sudo -n apt-get update && sudo -n apt-get install -y redis-server) 2>&1 \
  || (apt-get update && apt-get install -y redis-server) 2>&1
BASH
        ],
        'install_certbot' => [
            'label' => 'Install Certbot',
            'description' => 'certbot with the nginx plugin where available.',
            'confirm' => 'Install Certbot (and nginx plugin) with apt?',
            'timeout' => 900,
            'script' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
(sudo -n apt-get update && sudo -n apt-get install -y certbot python3-certbot-nginx) 2>&1 \
  || (apt-get update && apt-get install -y certbot python3-certbot-nginx) 2>&1
BASH
        ],
        'install_ufw' => [
            'label' => 'Install UFW',
            'description' => 'Uncomplicated Firewall package only (does not enable rules).',
            'confirm' => 'Install the ufw package?',
            'timeout' => 600,
            'script' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
(sudo -n apt-get update && sudo -n apt-get install -y ufw) 2>&1 \
  || (apt-get update && apt-get install -y ufw) 2>&1
BASH
        ],
        'install_chrony' => [
            'label' => 'Install Chrony',
            'description' => 'NTP client/server (chrony) for time sync.',
            'confirm' => 'Install chrony with apt?',
            'timeout' => 600,
            'script' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
(sudo -n apt-get update && sudo -n apt-get install -y chrony) 2>&1 \
  || (apt-get update && apt-get install -y chrony) 2>&1
BASH
        ],
    ],

];
