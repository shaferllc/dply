<?php

use App\Services\Insights\InsightRunCoordinator;
use App\Services\Insights\Runners\CpuRamUsageInsightRunner;
use App\Services\Insights\Runners\DiskCapacityInsightRunner;
use App\Services\Insights\Runners\HealthCheckUrlMissingInsightRunner;
use App\Services\Insights\Runners\LoadAverageInsightRunner;
use App\Services\Insights\Runners\MetricsMissingInsightRunner;
use App\Services\Insights\Runners\PhpEolSitesInsightRunner;
use App\Services\Insights\Runners\PipelineHeartbeatInsightRunner;
use App\Services\Insights\Runners\SslCertificateInsightRunner;
use App\Services\Insights\Runners\SupervisorRunningInsightRunner;

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
            'description' => 'Verify system clock is synchronized.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => null,
            'fix' => null,
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
            'description' => 'Check for available system updates.',
            'scope' => 'server',
            'requires_pro' => false,
            'runner' => null,
            'fix' => null,
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
                'action' => 'supervisor_start',
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
