<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $stream_key
 * @property ?string $server_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $kind
 * @property ?string $user_id
 * @property ?string $summary
 * @property array $snapshot
 * @property ?string $checksum
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Model $subject
 * @property-read ?Server $server
 * @property-read ?User $user
 */
class ConfigRevision extends Model
{
    use HasUlids;

    protected $fillable = [
        'stream_key',
        'server_id',
        'subject_type',
        'subject_id',
        'kind',
        'user_id',
        'summary',
        'snapshot',
        'checksum',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function scopeForStream(Builder $query, string $streamKey): Builder
    {
        return $query->where('stream_key', $streamKey)->orderByDesc('created_at')->orderByDesc('id');
    }
}
