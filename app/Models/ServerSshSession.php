<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $created_by_user_id
 * @property ?Carbon $expires_at
 * @property string $name
 * @property ?string $organization_id
 * @property ?Carbon $provisioned_at
 * @property string $public_key_fingerprint
 * @property ?Carbon $revoked_at
 * @property ?string $server_authorized_key_id
 * @property ?string $server_id
 * @property string $target_linux_user
 * @property-read ?Organization $organization
 * @property-read ?Server $server
 * @property-read ?User $createdBy
 * @property-read ?ServerAuthorizedKey $serverAuthorizedKey
 * @property-read ?ServerAuthorizedKey $authorizedKey
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<ServerAuthorizedKey, $this> */
    public function serverAuthorizedKey(): BelongsTo
    {
        return $this->belongsTo(ServerAuthorizedKey::class);
    }

    /** @return MorphOne<ServerAuthorizedKey, $this> */
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
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }
}
