<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ServerAuthorizedKey extends Model
{
    use HasUlids;

    protected $fillable = [
        'server_id',
        'target_linux_user',
        'managed_key_type',
        'managed_key_id',
        'name',
        'public_key',
        'synced_at',
        'review_after',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
            'review_after' => 'date',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function managedKey(): MorphTo
    {
        return $this->morphTo();
    }
}
