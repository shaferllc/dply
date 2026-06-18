<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      One execution of a CloudDeployTask. For pre/post/failed triggers the
 *                      run row is created by the deploy-sync pass that scrapes DO's per-job
 *                      status after each rollout; for MANUAL the row is created inline when
 *                      the operator hits Run now.
 *                      The trigger is snapshotted from the parent task at run time so
 *                      historical runs survive task renames or trigger changes.
 * @property ?string $cloud_deploy_task_id
 * @property ?string $deployment_id
 * @property int $duration_ms
 * @property ?string $error
 * @property int $exit_code
 * @property ?Carbon $finished_at
 * @property string $log_tail
 * @property array<string, mixed> $meta
 * @property ?Carbon $started_at
 * @property string $status
 * @property string $trigger
 * @property-read ?CloudDeployTask $task
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class CloudDeployTaskRun extends Model
{
    use HasUlids;

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

    /** @return array<string, string> */
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

    /** @return BelongsTo<CloudDeployTask, $this> */
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
