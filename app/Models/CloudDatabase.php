<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A managed database — a hosted Postgres / MySQL / Redis instance dply
 * provisions on a cloud provider and attaches to Cloud container sites.
 *
 * v1 backend is DigitalOcean Managed Databases. `backend_id` holds the
 * DO cluster id; once the cluster reports `online` the connection block
 * is stored (encrypted) in `connection`, from which the engine-specific
 * env-var map is derived on attach.
 */
class CloudDatabase extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETING = 'deleting';

    public const ENGINE_POSTGRES = 'postgres';

    public const ENGINE_MYSQL = 'mysql';

    public const ENGINE_REDIS = 'redis';

    public const BACKEND_DIGITALOCEAN = 'digitalocean_managed_database';

    /**
     * Portable size tier → DO Managed Database size slug. Mirrors the
     * container size_tier mapping in DigitalOceanAppPlatformBackend.
     *
     * @var array<string, string>
     */
    public const SIZE_TIERS = [
        'small' => 'db-s-1vcpu-1gb',
        'medium' => 'db-s-1vcpu-2gb',
        'large' => 'db-s-2vcpu-4gb',
    ];

    protected $fillable = [
        'organization_id',
        'name',
        'engine',
        'version',
        'size',
        'region',
        'backend',
        'backend_id',
        'provider_credential_id',
        'status',
        'connection',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'connection' => 'encrypted:array',
            'meta' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class, 'provider_credential_id');
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'cloud_database_site')
            ->using(CloudDatabaseSite::class)
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Map this size tier to the provider's database size slug. Unknown
     * tiers fall back to the smallest slug.
     */
    public function backendSizeSlug(): string
    {
        return self::SIZE_TIERS[$this->size] ?? self::SIZE_TIERS['small'];
    }

    /**
     * DigitalOcean's engine slug for this database (their API uses `pg`
     * for Postgres; `mysql` and `redis` are passed through unchanged).
     */
    public function backendEngineSlug(): string
    {
        return match ($this->engine) {
            self::ENGINE_POSTGRES => 'pg',
            self::ENGINE_MYSQL => 'mysql',
            self::ENGINE_REDIS => 'redis',
            default => 'pg',
        };
    }

    /**
     * The env-var map this database injects into an attached site's
     * env file. Derived from the decrypted `connection` block; returns
     * an empty array when the cluster has not produced a connection
     * yet (still provisioning).
     *
     * @return array<string, string>
     */
    public function connectionEnvVars(): array
    {
        // NB: read via getAttribute(), not $this->connection — the
        // latter collides with Eloquent's protected $connection property
        // (the DB connection name) when accessed from inside the model.
        $connection = $this->getAttribute('connection');
        $connection = is_array($connection) ? $connection : [];
        if ($connection === []) {
            return [];
        }

        $host = (string) ($connection['host'] ?? '');
        $port = (string) ($connection['port'] ?? '');
        $password = (string) ($connection['password'] ?? '');

        if ($this->engine === self::ENGINE_REDIS) {
            return [
                'REDIS_HOST' => $host,
                'REDIS_PORT' => $port,
                'REDIS_PASSWORD' => $password,
            ];
        }

        return [
            'DB_CONNECTION' => $this->engine === self::ENGINE_MYSQL ? 'mysql' : 'pgsql',
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => (string) ($connection['database'] ?? ''),
            'DB_USERNAME' => (string) ($connection['username'] ?? ''),
            'DB_PASSWORD' => $password,
        ];
    }

    /**
     * Env-var keys this database manages on a site — used by detach to
     * strip exactly the keys attach added, regardless of current values.
     *
     * @return list<string>
     */
    public function connectionEnvKeys(): array
    {
        if ($this->engine === self::ENGINE_REDIS) {
            return ['REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD'];
        }

        return ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
    }
}
