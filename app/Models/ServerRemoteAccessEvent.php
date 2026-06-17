<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */

class ServerRemoteAccessEvent extends Model
{
    use HasUlids;

    protected $fillable = [
        'server_id',
        'user_id',
        'linux_user',
        'credential_role',
        'source',
        'label',
        'started_at',
        'finished_at',
        'command_count',
        'failed',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'command_count' => 'integer',
            'failed' => 'boolean',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function isInFlight(): bool
    {
        return $this->finished_at === null;
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        $end = $this->finished_at ?? now();

        return max(0, (int) $this->started_at->diffInSeconds($end));
    }
}
