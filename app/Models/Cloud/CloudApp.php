<?php

namespace App\Models\Cloud;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A cloud-native application deployed to a dply Cloud cluster.
 *
 * CloudApps represent containerized applications running on Kubernetes.
 * They support auto-scaling, rolling deployments, and zero-downtime updates.
 */
class CloudApp extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_BUILDING = 'building';
    public const STATUS_DEPLOYING = 'deploying';
    public const STATUS_RUNNING = 'running';
    public const STATUS_ERROR = 'error';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DELETING = 'deleting';

    public const RUNTIME_PHP_83 = 'php-8.3';
    public const RUNTIME_PHP_84 = 'php-8.4';
    public const RUNTIME_RUBY_32 = 'ruby-3.2';
    public const RUNTIME_RUBY_33 = 'ruby-3.3';
    public const RUNTIME_NODE_20 = 'node-20';
    public const RUNTIME_NODE_22 = 'node-22';
    public const RUNTIME_PYTHON_311 = 'python-3.11';
    public const RUNTIME_PYTHON_312 = 'python-3.12';

    public const FRAMEWORK_LARAVEL = 'laravel';
    public const FRAMEWORK_SYMFONY = 'symfony';
    public const FRAMEWORK_RAILS = 'rails';
    public const FRAMEWORK_SINATRA = 'sinatra';
    public const FRAMEWORK_GENERIC = 'generic';

    protected $table = 'cloud_apps';

    protected $fillable = [
        'cloud_cluster_id',
        'organization_id',
        'name',
        'slug',
        'runtime',
        'framework',
        'git_repository_url',
        'git_branch',
        'git_commit_sha',
        'min_replicas',
        'max_replicas',
        'cpu_limit',
        'memory_limit',
        'env_vars',
        'domains',
        'ssl_status',
        'last_deploy_at',
        'last_deploy_sha',
        'container_image',
        'status',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'env_vars' => 'encrypted:array',
            'domains' => 'array',
            'last_deploy_at' => 'datetime',
            'cpu_limit' => 'decimal:2',
            'memory_limit' => 'integer',
            'min_replicas' => 'integer',
            'max_replicas' => 'integer',
            'meta' => 'array',
        ];
    }

    public function cloudCluster(): BelongsTo
    {
        return $this->belongsTo(CloudCluster::class, 'cloud_cluster_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function cloudDeploys(): HasMany
    {
        return $this->hasMany(CloudDeploy::class, 'cloud_app_id')
            ->orderByDesc('started_at');
    }

    public function latestDeploy(): ?CloudDeploy
    {
        return $this->cloudDeploys()->first();
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isDeploying(): bool
    {
        return in_array($this->status, [
            self::STATUS_BUILDING,
            self::STATUS_DEPLOYING,
            self::STATUS_PROVISIONING,
        ], true);
    }

    public function runtimeLabel(): string
    {
        return match ($this->runtime) {
            self::RUNTIME_PHP_83 => 'PHP 8.3',
            self::RUNTIME_PHP_84 => 'PHP 8.4',
            self::RUNTIME_RUBY_32 => 'Ruby 3.2',
            self::RUNTIME_RUBY_33 => 'Ruby 3.3',
            self::RUNTIME_NODE_20 => 'Node.js 20',
            self::RUNTIME_NODE_22 => 'Node.js 22',
            self::RUNTIME_PYTHON_311 => 'Python 3.11',
            self::RUNTIME_PYTHON_312 => 'Python 3.12',
            default => $this->runtime ?? 'Unknown',
        };
    }

    public function frameworkLabel(): string
    {
        return match ($this->framework) {
            self::FRAMEWORK_LARAVEL => 'Laravel',
            self::FRAMEWORK_SYMFONY => 'Symfony',
            self::FRAMEWORK_RAILS => 'Ruby on Rails',
            self::FRAMEWORK_SINATRA => 'Sinatra',
            self::FRAMEWORK_GENERIC => 'Generic',
            default => $this->framework ?? 'Unknown',
        };
    }

    /**
     * Get the primary domain for this app.
     */
    public function primaryDomain(): ?string
    {
        $domains = is_array($this->domains) ? $this->domains : [];

        return $domains[0] ?? null;
    }

    /**
     * Get all domains as a list.
     *
     * @return list<string>
     */
    public function allDomains(): array
    {
        $domains = is_array($this->domains) ? $this->domains : [];

        return array_values(array_filter($domains, fn ($d) => is_string($d) && $d !== ''));
    }

    /**
     * Get the default URL (either custom domain or cluster subdomain).
     */
    public function defaultUrl(): ?string
    {
        $primary = $this->primaryDomain();
        if ($primary) {
            return 'https://'.$primary;
        }

        // Generate subdomain based on cluster ingress
        $cluster = $this->cloudCluster;
        if ($cluster?->isReady()) {
            $endpoint = $cluster->ingressEndpoint();
            if ($endpoint) {
                return 'https://'.$this->slug.'.'.$endpoint;
            }
        }

        return null;
    }

    /**
     * Kubernetes namespace name for this app.
     */
    public function kubernetesNamespace(): string
    {
        return 'app-'.$this->id;
    }

    /**
     * Kubernetes deployment name.
     */
    public function kubernetesDeploymentName(): string
    {
        return $this->slug;
    }

    /**
     * Container image tag for the current deployment.
     */
    public function currentImageTag(): string
    {
        $sha = $this->last_deploy_sha ?? 'latest';

        return $this->slug.':'.$sha;
    }

    /**
     * Full container image path with registry.
     */
    public function fullImagePath(): string
    {
        $registry = config('services.digitalocean.container_registry');

        return $registry.'/'.$this->currentImageTag();
    }

    /**
     * Get environment variables as key-value array.
     */
    public function envVarsArray(): array
    {
        $vars = is_array($this->env_vars) ? $this->env_vars : [];

        return array_filter($vars, fn ($v) => is_string($v));
    }

    /**
     * Available runtimes for display in forms.
     */
    public static function availableRuntimes(): array
    {
        return [
            self::RUNTIME_PHP_83 => 'PHP 8.3',
            self::RUNTIME_PHP_84 => 'PHP 8.4',
            self::RUNTIME_RUBY_33 => 'Ruby 3.3',
            self::RUNTIME_RUBY_32 => 'Ruby 3.2',
            self::RUNTIME_NODE_22 => 'Node.js 22',
            self::RUNTIME_NODE_20 => 'Node.js 20',
        ];
    }

    /**
     * Available frameworks grouped by runtime.
     */
    public static function frameworksForRuntime(string $runtime): array
    {
        return match (true) {
            str_starts_with($runtime, 'php') => [
                self::FRAMEWORK_LARAVEL => 'Laravel',
                self::FRAMEWORK_SYMFONY => 'Symfony',
                self::FRAMEWORK_GENERIC => 'Generic PHP',
            ],
            str_starts_with($runtime, 'ruby') => [
                self::FRAMEWORK_RAILS => 'Ruby on Rails',
                self::FRAMEWORK_SINATRA => 'Sinatra',
                self::FRAMEWORK_GENERIC => 'Generic Ruby',
            ],
            str_starts_with($runtime, 'node') => [
                self::FRAMEWORK_GENERIC => 'Generic Node.js',
            ],
            default => [
                self::FRAMEWORK_GENERIC => 'Generic',
            ],
        };
    }

    /**
     * Default resource limits based on tier/runtime.
     */
    public static function defaultResourceSpec(string $runtime): array
    {
        return [
            'cpu_limit' => '0.5',
            'memory_limit' => 512, // MB
            'min_replicas' => 1,
            'max_replicas' => 3,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CloudApp $app): void {
            if (empty($app->slug)) {
                $app->slug = Str::slug($app->name) ?: 'app';
            }
        });
    }
}
