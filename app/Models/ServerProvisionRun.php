<?php

namespace App\Models;

use App\Modules\TaskRunner\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $attempt
 * @property ?Carbon $completed_at
 * @property string $rollback_status
 * @property ?string $server_id
 * @property ?Carbon $started_at
 * @property string $status
 * @property string $summary
 * @property ?string $task_id
 * @property-read ?Server $server
 * @property-read ?Task $task
 * @property-read Collection<int, ServerProvisionArtifact> $artifacts
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerProvisionRun extends Model
{
    use HasUlids;

    protected $fillable = [
        'server_id',
        'task_id',
        'attempt',
        'status',
        'rollback_status',
        'summary',
        'started_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /** @return HasMany<ServerProvisionArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(ServerProvisionArtifact::class)->orderBy('type')->orderBy('label');
    }
}
