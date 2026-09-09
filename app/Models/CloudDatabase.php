<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Redis\RedisConnectionTls;
use Database\Factories\CloudDatabaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      A managed database — a hosted Postgres / MySQL / Redis instance dply
 *                      provisions on a cloud provider and attaches to Cloud container sites.
 *                      v1 backend is DigitalOcean Managed Databases. `backend_id` holds the
 *                      DO cluster id; once the cluster reports `online` the connection block
 *                      is stored (encrypted) in `connection`, from which the engine-specific
 *                      env-var map is derived on attach.
 * @property string $backend
 * @property ?string $backend_id
 * @property array<string, mixed> $connection
 * @property string $engine
 * @property array<string, mixed>|null $meta
 * @property string $name
 * @property ?string $organization_id
 * @property ?string $provider_credential_id
 * @property string $region
 * @property string $size
 * @property string $status
 * @property string $version
 * @property-read ?Organization $organization
 * @property-read ?ProviderCredential $providerCredential
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CloudDatabase extends Model
{
    /** @use HasFactory<CloudDatabaseFactory> */
    use HasFactory, HasUlids;

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETING = 'deleting';

    public const ENGINE_POSTGRES = 'postgres';

    public const ENGINE_MYSQL = 'mysql';

    public const ENGINE_REDIS = 'redis';

    public const BACKEND_DIGITALOCEAN = 'digitalocean_managed_database';

    public const BACKEND_VULTR = 'vultr_managed_database';

    public const BACKEND_NEON = 'neon_serverless';

    public const BACKEND_PLANETSCALE = 'planetscale_serverless';

    public const BACKEND_SUPABASE = 'supabase_serverless';

    public const BACKEND_UPSTASH = 'upstash_serverless';

    /** Operator-supplied connection (RDS, Neon, etc.) — no provider provision. */
    public const BACKEND_EXTERNAL = 'external';

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'connection' => 'encrypted:array',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class, 'provider_credential_id');
    }

    /** @return BelongsToMany<Site, $this, CloudDatabaseSite, 'pivot'> */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'cloud_database_site')
            ->using(CloudDatabaseSite::class)
            ->withPivot('env_prefix')
            ->withTimestamps();
    }

    /**
     * Default env-var prefix for this engine. Used when a caller doesn't
     * pass a per-pivot prefix — preserves the original singleton behavior
     * (Postgres/MySQL → DB_*, Redis → REDIS_*).
     */
    public function defaultEnvPrefix(): string
    {
        return $this->engine === self::ENGINE_REDIS ? 'REDIS' : 'DB';
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
        return self::resolveSizeSlug((string) $this->size);
    }

    /**
     * Portable tier (small/medium/large) or an already-provider slug
     * (`db-s-4vcpu-8gb`) from the live catalog.
     */
    public static function resolveSizeSlug(string $size): string
    {
        $size = strtolower(trim($size));
        if ($size !== '' && isset(self::SIZE_TIERS[$size])) {
            return self::SIZE_TIERS[$size];
        }

        if ($size !== '' && preg_match('/^(db-s-|db-intel-|db-amd-|m-|gd-|so)/', $size) === 1) {
            return $size;
        }

        return self::SIZE_TIERS['small'];
    }

    /**
     * Provider engine slug for create. DigitalOcean's API uses `pg` for
     * Postgres. Managed Redis was discontinued 30 June 2025 — new DO
     * clusters must be `valkey` (wire-compatible; we still store `redis`
     * so the site gets REDIS_* env vars).
     */
    public function backendEngineSlug(): string
    {
        return match ($this->engine) {
            self::ENGINE_POSTGRES => 'pg',
            self::ENGINE_MYSQL => 'mysql',
            self::ENGINE_REDIS => $this->backend === self::BACKEND_DIGITALOCEAN ? 'valkey' : 'redis',
            default => 'pg',
        };
    }

    /**
     * Version to send on create. Valkey is 8; a leftover Redis 7 pin would
     * 400 against the Valkey engine.
     */
    public function backendEngineVersion(): ?string
    {
        $version = trim((string) $this->version);
        if ($this->engine === self::ENGINE_REDIS && $this->backend === self::BACKEND_DIGITALOCEAN) {
            if ($version === '' || $version === '7' || str_starts_with($version, '7.')) {
                return '8';
            }
        }

        return $version !== '' ? $version : null;
    }

    /**
     * The env-var map this database injects into an attached site's
     * env file. Derived from the decrypted `connection` block; returns
     * an empty array when the cluster has not produced a connection
     * yet (still provisioning).
     *
     * @return array<string, string>
     */
    public function connectionEnvVars(?string $prefix = null): array
    {
        $prefix = $prefix ?? $this->defaultEnvPrefix();

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
            return $this->redisConnectionEnvVars($prefix, $connection, $host, $port, $password);
        }

        $vars = [
            $prefix.'_CONNECTION' => $this->engine === self::ENGINE_MYSQL ? 'mysql' : 'pgsql',
            $prefix.'_HOST' => $host,
            $prefix.'_PORT' => $port,
            $prefix.'_DATABASE' => (string) ($connection['database'] ?? ''),
            $prefix.'_USERNAME' => (string) ($connection['username'] ?? ''),
            $prefix.'_PASSWORD' => $password,
        ];

        $ssl = ($connection['ssl'] ?? false) === true
            || in_array((string) ($connection['sslmode'] ?? ''), ['require', 'verify-full', 'verify-ca'], true);
        if ($ssl && $this->engine === self::ENGINE_POSTGRES) {
            $vars[$prefix.'_SSLMODE'] = (string) ($connection['sslmode'] ?? 'require');
        }

        return $vars;
    }

    /**
     * Env-var keys this database manages on a site — used by detach to
     * strip exactly the keys attach added, regardless of current values.
     *
     * @return list<string>
     */
    public function connectionEnvKeys(?string $prefix = null): array
    {
        $prefix = $prefix ?? $this->defaultEnvPrefix();

        if ($this->engine === self::ENGINE_REDIS) {
            return [
                $prefix.'_HOST',
                $prefix.'_PORT',
                $prefix.'_PASSWORD',
                $prefix.'_USERNAME',
                $prefix.'_SCHEME',
                $prefix.'_URL',
            ];
        }

        $keys = [$prefix.'_CONNECTION', $prefix.'_HOST', $prefix.'_PORT', $prefix.'_DATABASE', $prefix.'_USERNAME', $prefix.'_PASSWORD'];
        if ($this->engine === self::ENGINE_POSTGRES) {
            $keys[] = $prefix.'_SSLMODE';
        }

        return $keys;
    }

    public function isExternal(): bool
    {
        return $this->backend === self::BACKEND_EXTERNAL;
    }

    /**
     * DigitalOcean / Upstash managed Redis is TLS-only. A plaintext PhpRedis
     * dial to :25061 surfaces as "read error on connection" and a 500 after
     * deploy — inject rediss:// plus REDIS_SCHEME=tls so Laravel's default
     * `url` / `scheme` config actually handshakes.
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, string>
     */
    private function redisConnectionEnvVars(
        string $prefix,
        array $connection,
        string $host,
        string $port,
        string $password,
    ): array {
        $vars = [
            $prefix.'_HOST' => $host,
            $prefix.'_PORT' => $port,
            $prefix.'_PASSWORD' => $password,
        ];

        $username = trim((string) ($connection['username'] ?? $connection['user'] ?? ''));
        if ($username !== '') {
            $vars[$prefix.'_USERNAME'] = $username;
        }

        if (! $this->redisRequiresTls($connection, $host)) {
            return $vars;
        }

        if ($username === '') {
            $username = 'default';
            $vars[$prefix.'_USERNAME'] = $username;
        }

        $vars[$prefix.'_SCHEME'] = 'tls';
        $vars[$prefix.'_URL'] = 'rediss://'.rawurlencode($username).':'.rawurlencode($password)
            .'@'.$host.($port !== '' ? ':'.$port : '');

        return $vars;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function redisRequiresTls(array $connection, string $host): bool
    {
        if (($connection['ssl'] ?? false) === true) {
            return true;
        }

        if (in_array($this->backend, [self::BACKEND_DIGITALOCEAN, self::BACKEND_UPSTASH], true)) {
            return true;
        }

        return RedisConnectionTls::requiresTls($host, $connection['port'] ?? null);
    }
}
