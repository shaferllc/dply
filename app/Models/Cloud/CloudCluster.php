<?php

namespace App\Models\Cloud;

use App\Models\Organization;
use App\Models\ProviderCredential;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A managed Kubernetes cluster on DigitalOcean (DOKS).
 *
 * CloudClusters are the foundation of the dply Cloud product.
 * Each cluster is a DOKS cluster with an ingress controller, cert-manager,
 * and monitoring stack. Apps are deployed as pods within the cluster.
 */
class CloudCluster extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_READY = 'ready';
    public const STATUS_SCALING = 'scaling';
    public const STATUS_ERROR = 'error';
    public const STATUS_DELETING = 'deleting';

    public const TIER_STARTER = 'starter';
    public const TIER_PRO = 'pro';
    public const TIER_ENTERPRISE = 'enterprise';

    protected $table = 'cloud_clusters';

    protected $fillable = [
        'organization_id',
        'provider_credential_id',
        'name',
        'slug',
        'region',
        'tier',
        'do_kubernetes_cluster_id',
        'kubeconfig',
        'node_pool_spec',
        'status',
        'provisioned_at',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'node_pool_spec' => 'array',
            'kubeconfig' => 'encrypted',
            'provisioned_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class);
    }

    public function cloudApps(): HasMany
    {
        return $this->hasMany(CloudApp::class, 'cloud_cluster_id');
    }

    public function cloudDatabases(): HasMany
    {
        return $this->hasMany(CloudDatabase::class, 'cloud_cluster_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROVISIONING], true);
    }

    public function tierLabel(): string
    {
        return match ($this->tier) {
            self::TIER_STARTER => 'Starter',
            self::TIER_PRO => 'Pro',
            self::TIER_ENTERPRISE => 'Enterprise',
            default => 'Unknown',
        };
    }

    public function tierBasePrice(): int
    {
        return match ($this->tier) {
            self::TIER_STARTER => 2500, // $25.00
            self::TIER_PRO => 7500,     // $75.00
            self::TIER_ENTERPRISE => 20000, // $200.00
            default => 0,
        };
    }

    /**
     * Default node pool spec based on tier.
     */
    public static function defaultNodePoolSpec(string $tier): array
    {
        return match ($tier) {
            self::TIER_STARTER => [
                'size' => 's-1vcpu-2gb',
                'count' => 1,
                'min_nodes' => 1,
                'max_nodes' => 1,
                'autoscale' => false,
            ],
            self::TIER_PRO => [
                'size' => 's-2vcpu-4gb',
                'count' => 2,
                'min_nodes' => 2,
                'max_nodes' => 4,
                'autoscale' => true,
            ],
            self::TIER_ENTERPRISE => [
                'size' => 's-4vcpu-8gb',
                'count' => 3,
                'min_nodes' => 3,
                'max_nodes' => 10,
                'autoscale' => true,
            ],
            default => [
                'size' => 's-1vcpu-2gb',
                'count' => 1,
                'min_nodes' => 1,
                'max_nodes' => 1,
                'autoscale' => false,
            ],
        };
    }

    /**
     * Get the kubeconfig as a string for kubectl operations.
     */
    public function kubeconfigString(): ?string
    {
        $config = $this->kubeconfig;
        if (!is_string($config) || $config === '') {
            return null;
        }

        return $config;
    }

    /**
     * API endpoint for the cluster (from kubeconfig).
     */
    public function apiEndpoint(): ?string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $endpoint = $meta['api_endpoint'] ?? null;

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : null;
    }

    /**
     * Ingress IP or hostname for routing traffic.
     */
    public function ingressEndpoint(): ?string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $endpoint = $meta['ingress_endpoint'] ?? null;

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : null;
    }

    /**
     * Available regions for Cloud clusters (DigitalOcean regions with DOKS).
     */
    public static function availableRegions(): array
    {
        return [
            'nyc1' => ['name' => 'New York', 'country' => 'US'],
            'nyc3' => ['name' => 'New York 3', 'country' => 'US'],
            'ams3' => ['name' => 'Amsterdam', 'country' => 'NL'],
            'fra1' => ['name' => 'Frankfurt', 'country' => 'DE'],
            'lon1' => ['name' => 'London', 'country' => 'GB'],
            'sgp1' => ['name' => 'Singapore', 'country' => 'SG'],
            'blr1' => ['name' => 'Bangalore', 'country' => 'IN'],
            'syd1' => ['name' => 'Sydney', 'country' => 'AU'],
            'tor1' => ['name' => 'Toronto', 'country' => 'CA'],
        ];
    }

    /**
     * Validate that a region supports DOKS.
     */
    public static function isValidRegion(string $region): bool
    {
        return array_key_exists($region, self::availableRegions());
    }
}
