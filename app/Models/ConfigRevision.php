<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForStream(Builder $query, string $streamKey): Builder
    {
        return $query->where('stream_key', $streamKey)->orderByDesc('created_at')->orderByDesc('id');
    }
}
