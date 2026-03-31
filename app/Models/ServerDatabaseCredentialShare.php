<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerDatabaseCredentialShare extends Model
{
    use HasUlids;

    protected $table = 'server_database_credential_shares';

    protected $fillable = [
        'server_database_id',
        'user_id',
        'token',
        'expires_at',
        'views_remaining',
        'max_views',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function serverDatabase(): BelongsTo
    {
        return $this->belongsTo(ServerDatabase::class, 'server_database_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->views_remaining < 1;
    }
}
