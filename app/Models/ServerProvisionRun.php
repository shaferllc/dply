<?php

namespace App\Models;

use App\Modules\TaskRunner\Models\Task;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 */

class ServerProvisionRun extends Model
{
    /** @use HasFactory<ServerProvisionRunFactory> */
    use HasFactory, HasUlids;

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
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /** @return HasMany<ServerProvisionArtifact, $this> */
    public function artifacts(): HasMany {
        return $this->hasMany(ServerProvisionArtifact::class)->orderBy('type')->orderBy('label');
    }
}
