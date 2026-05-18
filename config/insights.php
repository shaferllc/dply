<?php

use App\Services\Insights\FixActions\ApplyPackageSecurityUpdatesFixAction;
use App\Services\Insights\FixActions\BumpFpmWorkersFixAction;
use App\Services\Insights\FixActions\EnableNtpFixAction;
use App\Services\Insights\FixActions\EnableUnattendedUpgradesFixAction;
use App\Services\Insights\FixActions\HardenSshConfigFixAction;
use App\Services\Insights\FixActions\InstallFail2banFixAction;
use App\Services\Insights\FixActions\SupervisorStartFixAction;
use App\Services\Insights\InsightRunCoordinator;
use App\Services\Insights\Runners\CpuRamUsageInsightRunner;
use App\Services\Insights\Runners\DatabaseConnectionsInsightRunner;
use App\Services\Insights\Runners\DiskCapacityInsightRunner;
use App\Services\Insights\Runners\Fail2banInsightRunner;
use App\Services\Insights\Runners\FailedSystemdUnitsInsightRunner;
use App\Services\Insights\Runners\HealthCheckUrlMissingInsightRunner;
use App\Services\Insights\Runners\HorizonRecommendedInsightRunner;
use App\Services\Insights\Runners\InnodbBufferPoolInsightRunner;
use App\Services\Insights\Runners\LaravelAppDebugInsightRunner;
use App\Services\Insights\Runners\LoadAverageInsightRunner;
use App\Services\Insights\Runners\MetricsMissingInsightRunner;
use App\Services\Insights\Runners\MysqlBinLogsInsightRunner;
use App\Services\Insights\Runners\NginxWorkerConnectionsInsightRunner;
use App\Services\Insights\Runners\NodejsUpdatesInsightRunner;
use App\Services\Insights\Runners\NoNotificationChannelsInsightRunner;
use App\Services\Insights\Runners\OctaneRecommendedInsightRunner;
use App\Services\Insights\Runners\OpcacheDisabledInsightRunner;
use App\Services\Insights\Runners\OpcacheFullInsightRunner;
use App\Services\Insights\Runners\PackageSecurityUpdatesInsightRunner;
use App\Services\Insights\Runners\PhpEolSitesInsightRunner;
use App\Services\Insights\Runners\PhpFpmWorkersUndersizedInsightRunner;
use App\Services\Insights\Runners\PhpMaxChildrenSaturatedInsightRunner;
use App\Services\Insights\Runners\PipelineHeartbeatInsightRunner;
use App\Services\Insights\Runners\RebootRequiredInsightRunner;
use App\Services\Insights\Runners\SshSecurityPostureInsightRunner;
use App\Services\Insights\Runners\SslCertificateInsightRunner;
use App\Services\Insights\Runners\SslExpirationInsightRunner;
use App\Services\Insights\Runners\StaleBackupsInsightRunner;
use App\Services\Insights\Runners\SupervisorRunningInsightRunner;
use App\Services\Insights\Runners\SystemClockSyncInsightRunner;
use App\Services\Insights\Runners\UnattendedUpgradesInsightRunner;

/**
 * Registered insight checks: `runner` null means not implemented yet (skipped by jobs).
 *
 * @see InsightRunCoordinator
 */
return [

    'schedule_server_minutes' => (int) env('INSIGHTS_SERVER_SCHEDULE_MINUTES', 60),

    'schedule_site_minutes' => (int) env('INSIGHTS_SITE_SCHEDULE_MINUTES', 120),

    /*
    | Queue server + site insight runs after a successful site deploy (correlation + fresh checks).
    */
    'queue_after_deploy' => (bool) env('INSIGHTS_QUEUE_AFTER_DEPLOY', true),

    /*
    | Queue a server insights run as soon as a fresh server provision succeeds, so the workspace
    | lands with a populated baseline instead of an empty Insights overview.
    */
    'queue_after_install' => (bool) env('INSIGHTS_QUEUE_AFTER_INSTALL', true),

    'thresholds' => [
        'cpu_warn_pct' => (float) env('INSIGHTS_CPU_WARN_PCT', 85),
        'mem_warn_pct' => (float) env('INSIGHTS_MEM_WARN_PCT', 85),
        'load_warn' => (float) env('INSIGHTS_LOAD_WARN', 4.0),
        'metrics_missing_minutes' => max(5, (int) env('INSIGHTS_METRICS_MISSING_MINUTES', 15)),
    ],

    /*
    | Org-level defaults (merged with organizations.insights_preferences).
    */
    'organization_defaults' => [
        'digest_non_critical' => (bool) env('INSIGHTS_DIGEST_NON_CRITICAL', false),
        /** daily | weekly — weekly digest is flushed by the scheduled weekly command. */
        'digest_frequency' => env('INSIGHTS_DIGEST_FREQUENCY', 'daily') === 'weekly' ? 'weekly' : 'daily',
        'quiet_hours_enabled' => (bool) env('INSIGHTS_QUIET_HOURS_ENABLED', false),
        'quiet_hours_start' => (int) env('INSIGHTS_QUIET_HOURS_START', 22),
        'quiet_hours_end' => (int) env('INSIGHTS_QUIET_HOURS_END', 7),
    ],

    'insights' => [

        /*
         * Synthetic: no remote checks. Confirms scheduled RunServerInsightsJob + recorder ran.
         * Default off; enable under Insights → Settings or set INSIGHTS_HEARTBEAT_DEFAULT_ENABLED=true.
         */
        'insights_pipeline_heartbeat' => [
            'label' => 'Insights pipeline heartbeat (test)',
            'description' => 'Creates a harmless info finding when Insights runs. Use it to verify scheduling and the Overview list. Does not send insight alert notifications.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => PipelineHeartbeatInsightRunner::class,
            'fix' => null,
            'default_enabled' => (bool) env('INSIGHTS_HEARTBEAT_DEFAULT_ENABLED', false),
            'notify_subscribers' => false,
        ],

        'cpu_ram_usage' => [
            'label' => 'CPU & RAM usage',
            'description' => 'Monitor CPU and RAM usage on your server.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => CpuRamUsageInsightRunner::class,
            'fix' => null,
            'parameters' => [
                'cpu_warn_pct' => [
                    'type' => 'number',
                    'label' => 'CPU warn %',
                    'min' => 50,
                    'max' => 99,
                    'default' => 85,
                ],
                'mem_warn_pct' => [
                    'type' => 'number',
                    'label' => 'RAM warn %',
                    'min' => 50,
                    'max' => 99,
                    'default' => 85,
                ],
            ],
        ],

        'php_eol_sites' => [
            'label' => 'End of life PHP sites',
            'description' => 'Detect sites running on end-of-life PHP versions.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => PhpEolSitesInsightRunner::class,
            'fix' => null,
            'requires' => ['php'],
        ],

        'disk_capacity_forecast' => [
            'label' => 'Disk capacity trend',
            'description' => 'Estimate disk headroom from recent metrics (approximate).',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => DiskCapacityInsightRunner::class,
            'fix' => null,
        ],

        'metrics_missing_or_stale' => [
            'label' => 'Metrics missing or stale',
            'description' => 'Warn when server monitoring has not stored a recent metrics sample.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => MetricsMissingInsightRunner::class,
            'fix' => null,
            'parameters' => [
                'stale_after_minutes' => [
                    'type' => 'number',
                    'label' => 'Warn after minutes without metrics',
                    'min' => 5,
                    'max' => 1440,
                    'default' => 15,
                ],
            ],
        ],

        'health_check_url_missing' => [
            'label' => 'HTTP health check URL missing',
            'description' => 'Remind you to add an app-level health check URL when the server hosts sites.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => HealthCheckUrlMissingInsightRunner::class,
            'fix' => null,
        ],

        'opcache_disabled' => [
            'label' => 'OPcache disabled',
            'description' => 'Detect PHP-FPM SAPIs running without OPcache enabled.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => OpcacheDisabledInsightRunner::class,
            'fix' => null,
            'requires' => ['php'],
        ],

        'load_average_high' => [
            'label' => 'Load average high',
            'description' => 'Monitor server load average.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => LoadAverageInsightRunner::class,
            'fix' => null,
            'parameters' => [
                'load_warn' => [
                    'type' => 'number',
                    'label' => 'Load (1m) warn',
                    'min' => 0,
                    'max' => 500,
                    'default' => 4,
                ],
            ],
        ],

        'system_clock_sync' => [
            'label' => 'System clock sync',
            'description' => 'Verify system clock is synchronized via NTP.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => SystemClockSyncInsightRunner::class,
            'fix' => [
                'handler' => EnableNtpFixAction::class,
            ],
        ],

        'innodb_buffer_pool' => [
            'label' => 'InnoDB buffer pool',
            'description' => 'Flag undersized innodb_buffer_pool relative to RAM and working set.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => InnodbBufferPoolInsightRunner::class,
            'fix' => null,
            'requires' => ['mysql'],
            'parameters' => [
                'min_ram_share_pct' => [
                    'type' => 'number',
                    'label' => 'Min RAM share for buffer pool (%)',
                    'min' => 5,
                    'max' => 90,
                    'default' => 25,
                ],
                'working_set_full_pct' => [
                    'type' => 'number',
                    'label' => 'Pages-data fullness that signals saturation (%)',
                    'min' => 50,
                    'max' => 100,
                    'default' => 95,
                ],
            ],
        ],

        'package_security_updates' => [
            'label' => 'Package & security updates',
            'description' => 'Detect outstanding security-suite package updates via apt.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => PackageSecurityUpdatesInsightRunner::class,
            'fix' => [
                'handler' => ApplyPackageSecurityUpdatesFixAction::class,
            ],
            'parameters' => [
                'min_security_updates' => [
                    'type' => 'number',
                    'label' => 'Min security updates to surface',
                    'min' => 1,
                    'max' => 100,
                    'default' => 1,
                ],
            ],
        ],

        'ssl_certificate_checks' => [
            'label' => 'SSL certificate checks',
            'description' => 'Check for SSL certificate issues.',
            'scope' => 'site',
            'requires_pro' => false,
            'runner' => SslCertificateInsightRunner::class,
            'fix' => null,
        ],

        'npm_vulnerabilities' => [
            'label' => 'NPM vulnerabilities',
            'description' => 'Scan for NPM package vulnerabilities.',
            'scope' => 'site',
            'requires_pro' => true,
            'runner' => null,
            'fix' => null,
        ],

        'php_max_children' => [
            'label' => 'PHP max children',
            'description' => 'Flag PHP-FPM pools currently at their pm.max_children worker ceiling.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => PhpMaxChildrenSaturatedInsightRunner::class,
            'fix' => null,
            'requires' => ['php'],
            'parameters' => [
                'saturation_pct' => [
                    'type' => 'number',
                    'label' => 'At-or-above this %, flag as saturated',
                    'min' => 50,
                    'max' => 100,
                    'default' => 100,
                ],
            ],
        ],

        /*
         * Suggestion + config-mutating fix: probe FPM saturation, bump pm.max_children
         * if active/max ≥ 0.85. Fix takes a timestamped backup, validates new content
         * with `php-fpm -tt`, then writes + reloads. backup_path is recorded in finding meta.
         */
        'php_fpm_workers_undersized' => [
            'label' => 'PHP-FPM workers undersized',
            'description' => 'Suggest bumping pm.max_children when FPM is running near its worker ceiling.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => PhpFpmWorkersUndersizedInsightRunner::class,
            'fix' => [
                'handler' => BumpFpmWorkersFixAction::class,
                'mutates_config' => true,
                'params' => [
                    'ram_share_pct' => 60,
                    'per_worker_mb' => 30,
                    'max_children_cap' => 256,
                ],
            ],
            'requires' => ['php'],
            'parameters' => [
                'saturation_ratio' => [
                    'type' => 'number',
                    'label' => 'Active/max ratio that triggers the suggestion',
                    'min' => 0.5,
                    'max' => 0.99,
                    'default' => 0.85,
                ],
            ],
        ],

        'opcache_full' => [
            'label' => 'OPcache full',
            'description' => 'Detect PHP OPcache memory or key-table pressure (OOM restarts).',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => OpcacheFullInsightRunner::class,
            'fix' => null,
            'requires' => ['php'],
            'parameters' => [
                'usage_warn_pct' => [
                    'type' => 'number',
                    'label' => 'Warn at memory used %',
                    'min' => 50,
                    'max' => 99,
                    'default' => 90,
                ],
                'keys_warn_pct' => [
                    'type' => 'number',
                    'label' => 'Warn at cached-keys used %',
                    'min' => 50,
                    'max' => 99,
                    'default' => 90,
                ],
            ],
        ],

        'supervisor_running' => [
            'label' => 'Supervisor running',
            'description' => 'Verify Supervisor service is running.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => SupervisorRunningInsightRunner::class,
            'fix' => [
                'handler' => SupervisorStartFixAction::class,
            ],
            'requires' => ['supervisor'],
        ],

        'nodejs_updates' => [
            'label' => 'Node.js updates',
            'description' => 'Detect installed Node.js majors at or near end-of-life.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => NodejsUpdatesInsightRunner::class,
            'fix' => null,
            'parameters' => [
                'warn_days' => [
                    'type' => 'number',
                    'label' => 'Warn when major reaches EOL within N days',
                    'min' => 1,
                    'max' => 365,
                    'default' => 90,
                ],
            ],
        ],

        'mysql_bin_logs' => [
            'label' => 'MySQL bin logs',
            'description' => 'Detect unbounded or oversized MySQL binary log retention.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => MysqlBinLogsInsightRunner::class,
            'fix' => null,
            'requires' => ['mysql'],
            'parameters' => [
                'bin_log_warn_pct' => [
                    'type' => 'number',
                    'label' => 'Warn when binlogs ≥ % of used space',
                    'min' => 5,
                    'max' => 90,
                    'default' => 30,
                ],
            ],
        ],

        'database_connections' => [
            'label' => 'Database connections',
            'description' => 'Detect MySQL connection-pool saturation against max_connections.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => DatabaseConnectionsInsightRunner::class,
            'fix' => null,
            'requires' => ['mysql', 'postgres'],
            'parameters' => [
                'warn_pct' => [
                    'type' => 'number',
                    'label' => 'Warn at max-used ratio (%)',
                    'min' => 50,
                    'max' => 99,
                    'default' => 80,
                ],
                'critical_pct' => [
                    'type' => 'number',
                    'label' => 'Critical at max-used ratio (%)',
                    'min' => 60,
                    'max' => 100,
                    'default' => 95,
                ],
            ],
        ],

        'nginx_worker_connections' => [
            'label' => 'Nginx worker connections',
            'description' => 'Flag nginx near its worker_connections × worker_processes ceiling.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => NginxWorkerConnectionsInsightRunner::class,
            'fix' => null,
            'requires' => ['nginx'],
            'parameters' => [
                'warn_pct' => [
                    'type' => 'number',
                    'label' => 'Warn at capacity %',
                    'min' => 30,
                    'max' => 95,
                    'default' => 60,
                ],
                'critical_pct' => [
                    'type' => 'number',
                    'label' => 'Critical at capacity %',
                    'min' => 50,
                    'max' => 100,
                    'default' => 85,
                ],
            ],
        ],

        /*
         * Suggestion: Laravel sites already running a supervisor queue worker but not on Horizon.
         * Emits with kind=suggestion. Signal: site has an active SupervisorProgram whose command
         * contains queue:work / queue:listen.
         */
        'horizon_recommended' => [
            'label' => 'Consider Laravel Horizon',
            'description' => 'Suggest Horizon on Laravel sites that already run queue workers via supervisor.',
            'scope' => 'site',
            'requires_pro' => false,
            'runner' => HorizonRecommendedInsightRunner::class,
            'fix' => null,
            'requires' => ['php', 'supervisor'],
        ],

        /*
         * Suggestion: Laravel sites without Octane that show sustained load. Emits with
         * kind=suggestion (skips notifications, separate UI section). Signal recorded in meta.
         */
        'octane_recommended' => [
            'label' => 'Consider Laravel Octane',
            'description' => 'Suggest enabling Laravel Octane on busy Laravel sites that aren\'t already using it.',
            'scope' => 'site',
            'requires_pro' => false,
            'runner' => OctaneRecommendedInsightRunner::class,
            'fix' => null,
            'requires' => ['php'],
            'parameters' => [
                'load_threshold' => [
                    'type' => 'number',
                    'label' => 'Load (1m) sustained threshold',
                    'min' => 0.5,
                    'max' => 64,
                    'default' => 4,
                ],
                'min_samples' => [
                    'type' => 'number',
                    'label' => 'Min samples in window',
                    'min' => 3,
                    'max' => 240,
                    'default' => 12,
                ],
            ],
        ],

        'composer_vulnerabilities' => [
            'label' => 'Composer vulnerabilities',
            'description' => 'Scan for Composer package vulnerabilities.',
            'scope' => 'site',
            'requires_pro' => true,
            'runner' => null,
            'fix' => null,
            'parameters' => [
                'severity' => [
                    'type' => 'select',
                    'options' => ['all', 'high', 'critical'],
                    'default' => 'all',
                    'label' => 'Severities',
                ],
            ],
        ],

        /*
         * SSH daemon posture — parses `sshd -T` and flags PasswordAuthentication yes,
         * PermitRootLogin yes, PermitEmptyPasswords yes, deprecated protocol 1, and
         * X11Forwarding. Severity is the highest across detected issues. Read-only.
         */
        'ssh_security_posture' => [
            'label' => 'SSH daemon posture',
            'description' => 'Audit sshd config for password auth, root login, weak settings.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => SshSecurityPostureInsightRunner::class,
            'fix' => [
                'handler' => HardenSshConfigFixAction::class,
                'mutates_config' => true,
            ],
        ],

        /*
         * Per-site SSL expiration countdown. Probes the live cert NotAfter via openssl
         * from the box itself, warns at ≤30 days, critical at ≤14 days. Skipped for
         * sites whose ssl_status isn't active (sibling runner handles those).
         */
        'ssl_certificate_expiring' => [
            'label' => 'SSL certificate expiring soon',
            'description' => 'Warn when SSL certs are within their renewal window.',
            'scope' => 'site',
            'requires_pro' => false,
            'runner' => SslExpirationInsightRunner::class,
            'fix' => null,
            'parameters' => [
                'warn_days' => [
                    'type' => 'number',
                    'label' => 'Warn when days remaining ≤',
                    'min' => 1,
                    'max' => 120,
                    'default' => 30,
                ],
                'critical_days' => [
                    'type' => 'number',
                    'label' => 'Critical when days remaining ≤',
                    'min' => 1,
                    'max' => 60,
                    'default' => 14,
                ],
            ],
        ],

        /*
         * Reboot required after kernel/libc patch. Reads /var/run/reboot-required +
         * /var/run/reboot-required.pkgs. Severity escalates from warn to critical
         * after `critical_after_days` (default 14).
         */
        'reboot_required' => [
            'label' => 'Reboot required',
            'description' => 'Surface pending kernel/libc updates that require a host reboot.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => RebootRequiredInsightRunner::class,
            'fix' => null,
            'parameters' => [
                'critical_after_days' => [
                    'type' => 'number',
                    'label' => 'Escalate to critical after days',
                    'min' => 1,
                    'max' => 90,
                    'default' => 14,
                ],
            ],
        ],

        /*
         * Failed systemd units. Lists unit names in failed state; warns on any,
         * escalates to critical at `critical_count` (default 3) — usually a cascade
         * from a deeper issue like an apt failure or storage problem.
         */
        'systemd_failed_units' => [
            'label' => 'Failed systemd units',
            'description' => 'Detect systemd units in a failed state.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => FailedSystemdUnitsInsightRunner::class,
            'fix' => null,
            'parameters' => [
                'critical_count' => [
                    'type' => 'number',
                    'label' => 'Critical when failed units ≥',
                    'min' => 1,
                    'max' => 50,
                    'default' => 3,
                ],
            ],
        ],

        /*
         * Stale backups. Walks server_backup_schedules + their last successful run
         * (database or site-files); flags any whose latest success is older than
         * `stale_after_hours` (default 48), critical at `critical_after_hours` (168).
         * Pure DB check, no SSH probe.
         */
        'stale_backups' => [
            'label' => 'Stale backups',
            'description' => 'Flag active backup schedules whose latest successful run is overdue.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => StaleBackupsInsightRunner::class,
            'fix' => null,
            'parameters' => [
                'stale_after_hours' => [
                    'type' => 'number',
                    'label' => 'Warn after hours without a successful backup',
                    'min' => 1,
                    'max' => 720,
                    'default' => 48,
                ],
                'critical_after_hours' => [
                    'type' => 'number',
                    'label' => 'Critical after hours without a successful backup',
                    'min' => 24,
                    'max' => 2160,
                    'default' => 168,
                ],
            ],
        ],

        /*
         * unattended-upgrades disabled. Detects missing package, disabled config,
         * or inactive timer. Suggestion if not installed (operator may have opted
         * out); warning if installed but not running.
         */
        'unattended_upgrades_disabled' => [
            'label' => 'Unattended security upgrades',
            'description' => 'Check that automatic security updates are configured and running.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => UnattendedUpgradesInsightRunner::class,
            'fix' => [
                'handler' => EnableUnattendedUpgradesFixAction::class,
                'mutates_config' => true,
            ],
        ],

        /*
         * fail2ban presence/activity. Suggestion if not installed; warning if
         * installed but the service isn't active.
         */
        'fail2ban_inactive' => [
            'label' => 'fail2ban inactive',
            'description' => 'Detect when fail2ban is missing or not running.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => Fail2banInsightRunner::class,
            'fix' => [
                'handler' => InstallFail2banFixAction::class,
                'mutates_config' => true,
            ],
        ],

        /*
         * Alerts have nowhere to go. Flags the org-no-channels case (warning) and
         * the server-no-subscriptions case (suggestion). Pure DB check.
         */
        'no_notification_channels' => [
            'label' => 'No notification channels',
            'description' => 'Surface when alerts won\'t reach anyone because no channels are configured.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => NoNotificationChannelsInsightRunner::class,
            'fix' => null,
        ],

        /*
         * Laravel APP_DEBUG=true with APP_ENV=production. Reads dply's encrypted
         * env cache (env_file_content); no SSH round-trip. Critical — leaks stack
         * traces and env values on errors.
         */
        'laravel_app_debug_enabled' => [
            'label' => 'Laravel APP_DEBUG=true in production',
            'description' => 'Flag Laravel sites where APP_DEBUG=true while APP_ENV=production.',
            'scope' => 'site',
            'requires_pro' => false,
            'runner' => LaravelAppDebugInsightRunner::class,
            'fix' => null,
            'requires' => ['php'],
        ],

    ],

];
