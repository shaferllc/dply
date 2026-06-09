<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ServerSshSession extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'server_id',
        'created_by_user_id',
        'server_authorized_key_id',
        'name',
        'public_key_fingerprint',
        'target_linux_user',
        'expires_at',
        'provisioned_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function serverAuthorizedKey(): BelongsTo
    {
        return $this->belongsTo(ServerAuthorizedKey::class);
    }

    public function authorizedKey(): MorphOne
    {
        return $this->morphOne(ServerAuthorizedKey::class, 'managedKey', 'managed_key_type', 'managed_key_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }
}
