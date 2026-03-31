<?php

namespace App\Models;

use App\Modules\TaskRunner\Models\Task;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerProvisionRun extends Model
{
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

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(ServerProvisionArtifact::class)->orderBy('type')->orderBy('label');
    }
}
