<?php

namespace App\Models;

use App\Models\Concerns\DescribesCronExpression;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property bool $alert_on_failure
 * @property bool $alert_on_pattern_match
 * @property string $alert_pattern
 * @property ?string $applied_template_id
 * @property string $command
 * @property string $cron_expression
 * @property ?string $depends_on_job_id
 * @property ?string $description
 * @property bool $enabled
 * @property string $env_prefix
 * @property bool $is_synced
 * @property ?Carbon $last_run_at
 * @property string $last_run_output
 * @property string $last_sync_error
 * @property bool $last_synced_enabled
 * @property string $maintenance_tag
 * @property string $managed_block
 * @property string $managed_signature
 * @property string $overlap_policy
 * @property string $schedule_timezone
 * @property ?string $server_id
 * @property ?string $site_id
 * @property bool $system_managed
 * @property string $user
 * @property-read ?Server $server
 * @property-read ?Site $site
 * @property-read ?self $dependsOn
 * @property-read Collection<int, self> $dependentJobs
 * @property-read ?OrganizationCronJobTemplate $appliedTemplate
 * @property-read Collection<int, ServerCronJobRun> $runs
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerCronJob extends Model
{
    use DescribesCronExpression, HasUlids;

    public const OVERLAP_ALLOW = 'allow';

    public const OVERLAP_SKIP_IF_RUNNING = 'skip_if_running';

    protected $table = 'server_cron_jobs';

    /**
     * Identifier in the `managed_block` column for the metrics push
     * agent's `# BEGIN DPLY METRICS GUEST` crontab block. Used by
     * DeployGuestMetricsCallbackEnvJob to upsert a read-only mirror
     * row so the workspace cron list shows the auto-installed line.
     */
    public const MANAGED_BLOCK_METRICS = 'metrics_guest';

    protected $fillable = [
        'server_id',
        'cron_expression',
        'command',
        'user',
        'enabled',
        'description',
        'site_id',
        'is_synced',
        'last_synced_enabled',
        'last_sync_error',
        'last_run_at',
        'last_run_output',
        'schedule_timezone',
        'overlap_policy',
        'alert_on_failure',
        'alert_on_pattern_match',
        'alert_pattern',
        'env_prefix',
        'depends_on_job_id',
        'maintenance_tag',
        'applied_template_id',
        'system_managed',
        'managed_block',
        'managed_signature',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_synced' => 'boolean',
            'last_synced_enabled' => 'boolean',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'alert_on_failure' => 'boolean',
            'alert_on_pattern_match' => 'boolean',
            'system_managed' => 'boolean',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<self, $this> */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_job_id');
    }

    /** @return HasMany<self, $this> */
    public function dependentJobs(): HasMany
    {
        return $this->hasMany(self::class, 'depends_on_job_id');
    }

    /** @return BelongsTo<OrganizationCronJobTemplate, $this> */
    public function appliedTemplate(): BelongsTo
    {
        return $this->belongsTo(OrganizationCronJobTemplate::class, 'applied_template_id');
    }

    /** @return HasMany<ServerCronJobRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ServerCronJobRun::class, 'server_cron_job_id');
    }
}
