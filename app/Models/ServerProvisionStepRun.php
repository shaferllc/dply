<?php

namespace App\Models;

use App\Modules\TaskRunner\Models\Task;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * One row per provision step per run. Powers the data-driven ETA
 * surfaced on the provision-journey UI ("Avg 1m 25s from 12 previous
 * runs") in place of the old static "Usually X minutes" copy.
 */
class ServerProvisionStepRun extends Model
{
    /** @use HasFactory<ServerProvisionStepRunFactory> */
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_seconds' => 'integer',
            'resumed' => 'boolean',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ServerProvisionRun, $this> */
    public function provisionRun(): BelongsTo {
        return $this->belongsTo(ServerProvisionRun::class, 'server_provision_run_id');
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
