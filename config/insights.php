<?php

use App\Services\Insights\FixActions\ApplyPackageSecurityUpdatesFixAction;
use App\Services\Insights\FixActions\BumpFpmWorkersFixAction;
use App\Services\Insights\FixActions\EnableNtpFixAction;
use App\Services\Insights\FixActions\SupervisorStartFixAction;
use App\Services\Insights\InsightRunCoordinator;
use App\Services\Insights\Runners\CpuRamUsageInsightRunner;
use App\Services\Insights\Runners\DiskCapacityInsightRunner;
use App\Services\Insights\Runners\HealthCheckUrlMissingInsightRunner;
use App\Services\Insights\Runners\HorizonRecommendedInsightRunner;
use App\Services\Insights\Runners\LoadAverageInsightRunner;
use App\Services\Insights\Runners\MetricsMissingInsightRunner;
use App\Services\Insights\Runners\OctaneRecommendedInsightRunner;
use App\Services\Insights\Runners\PackageSecurityUpdatesInsightRunner;
use App\Services\Insights\Runners\PhpEolSitesInsightRunner;
use App\Services\Insights\Runners\PhpFpmWorkersUndersizedInsightRunner;
use App\Services\Insights\Runners\PipelineHeartbeatInsightRunner;
use App\Services\Insights\Runners\SslCertificateInsightRunner;
use App\Services\Insights\Runners\SupervisorRunningInsightRunner;
use App\Services\Insights\Runners\SystemClockSyncInsightRunner;

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
            'description' => 'Check if OPcache is disabled.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => null,
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
            'description' => 'Monitor InnoDB buffer pool usage.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => null,
            'fix' => null,
            'requires' => ['mysql'],
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
            'description' => 'Check if PHP-FPM max children limit is being reached.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => null,
            'fix' => null,
            'requires' => ['php'],
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
            'description' => 'Monitor OPcache memory usage.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => null,
            'fix' => null,
            'requires' => ['php'],
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
            'description' => 'Check for Node.js updates.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => null,
            'fix' => null,
        ],

        'mysql_bin_logs' => [
            'label' => 'MySQL bin logs',
            'description' => 'Monitor MySQL binary log growth.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => null,
            'fix' => null,
            'requires' => ['mysql'],
        ],

        'database_connections' => [
            'label' => 'Database connections',
            'description' => 'Check database connection limits.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => null,
            'fix' => null,
            'requires' => ['mysql', 'postgres'],
        ],

        'nginx_worker_connections' => [
            'label' => 'Nginx worker connections',
            'description' => 'Monitor Nginx worker connections.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => null,
            'fix' => null,
            'requires' => ['nginx'],
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

    ],

];
