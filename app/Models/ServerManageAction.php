<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerManageAction extends Model
{
    use HasUlids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'server_id',
        'user_id',
        'task_name',
        'label',
        'status',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
