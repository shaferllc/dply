<?php

namespace App\Models\Cloud;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A managed database instance for Cloud applications.
 *
 * Uses DigitalOcean Managed Databases (PostgreSQL, MySQL, Redis).
 */
class CloudDatabase extends Model
{
    use HasFactory, HasUlids;

    public const ENGINE_POSTGRESQL = 'postgresql';
    public const ENGINE_MYSQL = 'mysql';
    public const ENGINE_REDIS = 'redis';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_READY = 'ready';
    public const STATUS_ERROR = 'error';
    public const STATUS_DELETING = 'deleting';

    protected $table = 'cloud_databases';

    protected $fillable = [
        'cloud_cluster_id',
        'organization_id',
        'cloud_app_id',
        'name',
        'engine',
        'version',
        'size',
        'do_database_id',
        'connection_details',
        'backup_retention_days',
        'high_availability',
        'status',
        'provisioned_at',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'connection_details' => 'encrypted:array',
            'backup_retention_days' => 'integer',
            'high_availability' => 'boolean',
            'provisioned_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function cloudCluster(): BelongsTo
    {
        return $this->belongsTo(CloudCluster::class, 'cloud_cluster_id');
    }

    public function cloudApp(): BelongsTo
    {
        return $this->belongsTo(CloudApp::class, 'cloud_app_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function engineLabel(): string
    {
        return match ($this->engine) {
            self::ENGINE_POSTGRESQL => 'PostgreSQL',
            self::ENGINE_MYSQL => 'MySQL',
            self::ENGINE_REDIS => 'Redis',
            default => $this->engine ?? 'Unknown',
        };
    }

    /**
     * Get connection string for the database.
     */
    public function connectionString(): ?string
    {
        $details = is_array($this->connection_details) ? $this->connection_details : [];
        $uri = $details['uri'] ?? null;

        return is_string($uri) ? $uri : null;
    }

    /**
     * Get host for the database.
     */
    public function host(): ?string
    {
        $details = is_array($this->connection_details) ? $this->connection_details : [];
        $host = $details['host'] ?? null;

        return is_string($host) ? $host : null;
    }

    /**
     * Get port for the database.
     */
    public function port(): ?int
    {
        $details = is_array($this->connection_details) ? $this->connection_details : [];
        $port = $details['port'] ?? null;

        return is_int($port) ? $port : null;
    }

    /**
     * Get default database name.
     */
    public function databaseName(): ?string
    {
        $details = is_array($this->connection_details) ? $this->connection_details : [];
        $db = $details['database'] ?? null;

        return is_string($db) ? $db : null;
    }

    /**
     * Get username.
     */
    public function username(): ?string
    {
        $details = is_array($this->connection_details) ? $this->connection_details : [];
        $user = $details['user'] ?? null;

        return is_string($user) ? $user : null;
    }

    /**
     * Get password.
     */
    public function password(): ?string
    {
        $details = is_array($this->connection_details) ? $this->connection_details : [];
        $pass = $details['password'] ?? null;

        return is_string($pass) ? $pass : null;
    }

    /**
     * Available database engines.
     */
    public static function availableEngines(): array
    {
        return [
            self::ENGINE_POSTGRESQL => 'PostgreSQL',
            self::ENGINE_MYSQL => 'MySQL',
            self::ENGINE_REDIS => 'Redis',
        ];
    }

    /**
     * Default versions for each engine.
     */
    public static function defaultVersion(string $engine): string
    {
        return match ($engine) {
            self::ENGINE_POSTGRESQL => '16',
            self::ENGINE_MYSQL => '8',
            self::ENGINE_REDIS => '7',
            default => 'latest',
        };
    }

    /**
     * Available sizes with pricing (in cents per month).
     */
    public static function availableSizes(): array
    {
        return [
            'db-s-1vcpu-1gb' => ['name' => '1 vCPU / 1 GB', 'price' => 1500],
            'db-s-1vcpu-2gb' => ['name' => '1 vCPU / 2 GB', 'price' => 3000],
            'db-s-2vcpu-4gb' => ['name' => '2 vCPU / 4 GB', 'price' => 6000],
            'db-s-4vcpu-8gb' => ['name' => '4 vCPU / 8 GB', 'price' => 12000],
            'db-s-6vcpu-16gb' => ['name' => '6 vCPU / 16 GB', 'price' => 24000],
            'db-s-8vcpu-32gb' => ['name' => '8 vCPU / 32 GB', 'price' => 48000],
        ];
    }

    /**
     * Format connection details from DO API response.
     */
    public static function formatConnectionDetails(array $database): array
    {
        $conn = $database['connection'] ?? [];

        return [
            'host' => $conn['host'] ?? null,
            'port' => $conn['port'] ?? null,
            'user' => $conn['user'] ?? null,
            'password' => $conn['password'] ?? null,
            'database' => $conn['database'] ?? null,
            'uri' => $conn['uri'] ?? null,
            'ssl' => $conn['ssl'] ?? true,
        ];
    }
}
