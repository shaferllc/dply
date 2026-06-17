<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $duration_ms
 * @property ?string $error_message
 * @property string $exit_code
 * @property ?Carbon $finished_at
 * @property string $output
 * @property string $run_ulid
 * @property ?string $server_cron_job_id
 * @property ?Carbon $started_at
 * @property string $status
 * @property string $trigger
 * @property-read ?ServerCronJob $cronJob
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerCronJobRun extends Model
{
    use HasUlids;

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_FAILED = 'failed';

    protected $table = 'server_cron_job_runs';

    protected $fillable = [
        'server_cron_job_id',
        'run_ulid',
        'trigger',
        'status',
        'exit_code',
        'duration_ms',
        'output',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServerCronJob, $this> */
    public function cronJob(): BelongsTo
    {
        return $this->belongsTo(ServerCronJob::class, 'server_cron_job_id');
    }
}
