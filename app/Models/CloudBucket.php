<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * Object-storage bucket attached to one or more Cloud sites.
 *
 * v1 backend is DigitalOcean Spaces; AWS S3 and Cloudflare R2 are
 * planned follow-ups. The shape mirrors {@see CloudDatabase} on purpose
 * so the attach / detach / multi-site story works the same way: each
 * pivot row carries its own env_prefix and connectionEnvVars() emits
 * a Laravel-style S3 connection block under that prefix.
 *
 * This model is created in 'pending' state when the user adds a bucket
 * on the create page. Actual provider provisioning (creating the
 * Spaces/S3 bucket, generating a scoped access key) happens in a
 * follow-up PR — until then the connection blob is empty and
 * connectionEnvVars() returns [].
 */
class CloudBucket extends Model
{
    /** @use HasFactory<CloudBucketFactory> */
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETING = 'deleting';

    public const BACKEND_DIGITALOCEAN_SPACES = 'digitalocean_spaces';

    public const BACKEND_AWS_S3 = 'aws_s3';

    public const BACKEND_CLOUDFLARE_R2 = 'cloudflare_r2';

    protected $fillable = [
        'organization_id',
        'name',
        'backend',
        'backend_id',
        'region',
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
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function providerCredential(): BelongsTo {
        return $this->belongsTo(ProviderCredential::class, 'provider_credential_id');
    }

    /** @return BelongsToMany<Site, $this> */
    public function sites(): BelongsToMany {
        return $this->belongsToMany(Site::class, 'cloud_bucket_site')
            ->using(CloudBucketSite::class)
            ->withPivot('env_prefix')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function defaultEnvPrefix(): string
    {
        return 'S3';
    }

    /**
     * Connection env vars an attached site receives. Returns [] when the
     * bucket is still pending — same convention as CloudDatabase — so
     * AttachCloudBucketJob (future) can re-merge once the connection blob
     * is populated by provisioning.
     *
     * @return array<string, string>
     */
    public function connectionEnvVars(?string $prefix = null): array
    {
        $prefix = $prefix ?? $this->defaultEnvPrefix();

        $connection = $this->getAttribute('connection');
        $connection = is_array($connection) ? $connection : [];
        if ($connection === []) {
            return [];
        }

        return [
            $prefix.'_BUCKET' => (string) ($connection['bucket'] ?? $this->name),
            $prefix.'_REGION' => (string) ($connection['region'] ?? $this->region ?? ''),
            $prefix.'_ENDPOINT' => (string) ($connection['endpoint'] ?? ''),
            $prefix.'_ACCESS_KEY_ID' => (string) ($connection['access_key_id'] ?? ''),
            $prefix.'_SECRET_ACCESS_KEY' => (string) ($connection['secret_access_key'] ?? ''),
        ];
    }

    /**
     * Keys the bucket manages on a site — used by detach to strip exactly
     * what attach added, regardless of current values.
     *
     * @return list<string>
     */
    public function connectionEnvKeys(?string $prefix = null): array
    {
        $prefix = $prefix ?? $this->defaultEnvPrefix();

        return [
            $prefix.'_BUCKET',
            $prefix.'_REGION',
            $prefix.'_ENDPOINT',
            $prefix.'_ACCESS_KEY_ID',
            $prefix.'_SECRET_ACCESS_KEY',
        ];
    }
}
