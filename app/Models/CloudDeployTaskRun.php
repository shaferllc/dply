<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One execution of a CloudDeployTask. For pre/post/failed triggers the
 * run row is created by the deploy-sync pass that scrapes DO's per-job
 * status after each rollout; for MANUAL the row is created inline when
 * the operator hits Run now.
 *
 * The trigger is snapshotted from the parent task at run time so
 * historical runs survive task renames or trigger changes.
 */
class CloudDeployTaskRun extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'cloud_deploy_task_id',
        'deployment_id',
        'trigger',
        'status',
        'exit_code',
        'started_at',
        'finished_at',
        'duration_ms',
        'log_tail',
        'error',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CloudDeployTask::class, 'cloud_deploy_task_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
        ], true);
    }
}
