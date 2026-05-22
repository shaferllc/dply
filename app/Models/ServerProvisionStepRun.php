<?php

namespace App\Models;

use App\Modules\TaskRunner\Models\Task;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per provision step per run. Powers the data-driven ETA
 * surfaced on the provision-journey UI ("Avg 1m 25s from 12 previous
 * runs") in place of the old static "Usually X minutes" copy.
 */
class ServerProvisionStepRun extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'server_id',
        'organization_id',
        'server_provision_run_id',
        'task_id',
        'label_hash',
        'label',
        'started_at',
        'completed_at',
        'duration_seconds',
        'resumed',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_seconds' => 'integer',
            'resumed' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function provisionRun(): BelongsTo
    {
        return $this->belongsTo(ServerProvisionRun::class, 'server_provision_run_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
