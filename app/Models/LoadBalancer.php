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
 * @property string $algorithm
 * @property ?string $error_message
 * @property ?string $hetzner_network_id
 * @property string $load_balancer_type
 * @property array<string, mixed> $meta
 * @property string $name
 * @property ?string $organization_id
 * @property string $private_ip
 * @property string $provider
 * @property ?string $provider_credential_id
 * @property ?string $provider_id
 * @property string $public_ipv4
 * @property string $public_ipv6
 * @property string $region
 * @property ?string $server_id
 * @property string $status
 * @property bool $sticky_sessions
 * @property-read ?Organization $organization
 * @property-read ?ProviderCredential $providerCredential
 * @property-read Collection<int, LoadBalancerTarget> $targets
 * @property-read Collection<int, LoadBalancerService> $services
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class LoadBalancer extends Model
{
    use HasUlids;

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_RUNNING = 'running';

    public const STATUS_ERROR = 'error';

    public const STATUS_DELETING = 'deleting';

    public const ALGORITHM_ROUND_ROBIN = 'round_robin';

    public const ALGORITHM_LEAST_CONNECTIONS = 'least_connections';

    public const PROVIDER_HETZNER = 'hetzner';

    public const PROVIDER_HAPROXY = 'haproxy';

    public const TYPES = ['lb11', 'lb21', 'lb31'];

    protected $fillable = [
        'organization_id',
        'provider_credential_id',
        'server_id',
        'provider_id',
        'name',
        'provider',
        'region',
        'load_balancer_type',
        'algorithm',
        'status',
        'public_ipv4',
        'public_ipv6',
        'private_ip',
        'hetzner_network_id',
        'error_message',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'sticky_sessions' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The HAProxy server (software LBs only). *
     * @return BelongsTo<Server, $this>
     */
    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isSoftware(): bool
    {
        return $this->provider === self::PROVIDER_HAPROXY;
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class);
    }

    /** @return HasMany<LoadBalancerTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(LoadBalancerTarget::class);
    }

    /** @return HasMany<LoadBalancerService, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(LoadBalancerService::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'lb11' => 'LB11 (up to 25 targets)',
            'lb21' => 'LB21 (up to 100 targets)',
            'lb31' => 'LB31 (up to 500 targets)',
            default => ucfirst($type),
        };
    }
}
