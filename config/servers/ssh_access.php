<?php

declare(strict_types=1);
use App\Jobs\AddEdgeProxyJob;
use App\Modules\Backups\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\InstallDatabaseEngineJob;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\RemoveEdgeProxyJob;
use App\Jobs\SwitchServerWebserverJob;
use App\Jobs\SyncAuthorizedKeysJob;

return [

    'stale_drift_hours' => max(1, (int) env('SERVER_SSH_ACCESS_STALE_DRIFT_HOURS', 24)),

    'timeline_bucket_hours' => max(1, (int) env('SERVER_SSH_ACCESS_TIMELINE_BUCKET_HOURS', 24)),

    'timeline_max_lanes' => max(1, (int) env('SERVER_SSH_ACCESS_TIMELINE_MAX_LANES', 24)),

    'timeline_max_events' => max(1, (int) env('SERVER_SSH_ACCESS_TIMELINE_MAX_EVENTS', 40)),

    'timeline_events_per_page' => max(1, (int) env('SERVER_SSH_ACCESS_TIMELINE_EVENTS_PER_PAGE', 8)),

    /*
    |--------------------------------------------------------------------------
    | Platform SSH access logging
    |--------------------------------------------------------------------------
    | When true, queue jobs and synchronous console actions log rows to
    | server_remote_access_events whenever SshConnection connects.
    */
    'log_remote_access' => filter_var(env('SERVER_SSH_ACCESS_LOG_REMOTE', true), FILTER_VALIDATE_BOOLEAN),

    'remote_access_timeline_limit' => max(1, (int) env('SERVER_SSH_ACCESS_REMOTE_TIMELINE_LIMIT', 200)),

    'remote_access_command_preview_max' => max(40, (int) env('SERVER_SSH_ACCESS_REMOTE_COMMAND_PREVIEW_MAX', 120)),

    /** @var list<class-string> Queue job classes to exclude from platform access logging. */
    'skip_job_classes' => [],

    /**
     * Human labels for queue jobs on the access graph (class FQCN or basename).
     *
     * @var array<string, string>
     */
    'job_labels' => [
        AddEdgeProxyJob::class => 'Install edge proxy',
        RemoveEdgeProxyJob::class => 'Remove edge proxy',
        SwitchServerWebserverJob::class => 'Switch webserver',
        ProvisionSiteJob::class => 'Provision site',
        InstallDatabaseEngineJob::class => 'Install database engine',
        ExportServerDatabaseBackupJob::class => 'Export database backup',
        SyncAuthorizedKeysJob::class => 'Sync authorized keys',
    ],

];
