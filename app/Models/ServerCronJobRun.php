<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function cronJob(): BelongsTo
    {
        return $this->belongsTo(ServerCronJob::class, 'server_cron_job_id');
    }
}
