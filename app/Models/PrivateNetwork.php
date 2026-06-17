<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 *                      An org-level private network (Hetzner private network or DigitalOcean VPC).
 *                      Servers that belong to the network reference it via `private_network_id`.
 *                      Routes are stored on Hetzner's side — fetched live via the API.
 * @property string $ip_range
 * @property array<string, mixed> $meta
 * @property string $name
 * @property string $network_zone
 * @property ?string $organization_id
 * @property string $provider
 * @property ?string $provider_credential_id
 * @property ?string $provider_id
 * @property-read ?Organization $organization
 * @property-read ?ProviderCredential $providerCredential
 * @property-read Collection<int, Server> $servers
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class PrivateNetwork extends Model
{
    use HasUlids;

    public const PROVIDER_HETZNER = 'hetzner';

    public const PROVIDER_DO = 'digitalocean';

    public const PROVIDER_VULTR = 'vultr';

    public const PROVIDER_LINODE = 'linode';

    protected $fillable = [
        'organization_id',
        'provider_credential_id',
        'provider_id',
        'name',
        'provider',
        'ip_range',
        'network_zone',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class);
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'private_network_id');
    }

    public function isHetzner(): bool
    {
        return $this->provider === self::PROVIDER_HETZNER;
    }

    public function hetznerNetworkId(): ?int
    {
        $id = (int) $this->provider_id;

        return $id > 0 ? $id : null;
    }
}
