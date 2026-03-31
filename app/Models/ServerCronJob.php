<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerCronJob extends Model
{
    use HasUlids;

    public const OVERLAP_ALLOW = 'allow';

    public const OVERLAP_SKIP_IF_RUNNING = 'skip_if_running';

    protected $table = 'server_cron_jobs';

    protected $fillable = [
        'server_id',
        'cron_expression',
        'command',
        'user',
        'enabled',
        'description',
        'site_id',
        'is_synced',
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
    ];

    protected function casts(): array
    {
        return [
            'is_synced' => 'boolean',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'alert_on_failure' => 'boolean',
            'alert_on_pattern_match' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_job_id');
    }

    public function dependentJobs(): HasMany
    {
        return $this->hasMany(self::class, 'depends_on_job_id');
    }

    public function appliedTemplate(): BelongsTo
    {
        return $this->belongsTo(OrganizationCronJobTemplate::class, 'applied_template_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ServerCronJobRun::class, 'server_cron_job_id');
    }
}
