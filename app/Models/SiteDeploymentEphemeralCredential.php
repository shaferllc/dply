<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $organization_id
 * @property string $private_key_encrypted
 * @property ?Carbon $provisioned_at
 * @property string $public_key_fingerprint
 * @property ?Carbon $revoked_at
 * @property ?string $server_authorized_key_id
 * @property ?string $server_id
 * @property ?string $site_deployment_id
 * @property-read ?SiteDeployment $siteDeployment
 * @property-read ?Organization $organization
 * @property-read ?Server $server
 * @property-read ?ServerAuthorizedKey $serverAuthorizedKey
 * @property-read ?ServerAuthorizedKey $authorizedKey
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SiteDeploymentEphemeralCredential extends Model
{
    use HasUlids;

    protected $fillable = [
        'site_deployment_id',
        'organization_id',
        'server_id',
        'server_authorized_key_id',
        'public_key_fingerprint',
        'private_key_encrypted',
        'provisioned_at',
        'revoked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provisioned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SiteDeployment, $this> */
    public function siteDeployment(): BelongsTo
    {
        return $this->belongsTo(SiteDeployment::class);
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
}
