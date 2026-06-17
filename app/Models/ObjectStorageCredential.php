<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * A reusable S3 API key pair for an object-storage provider (DigitalOcean
 * Spaces keys, Hetzner S3 credentials, …), scoped to an organization so the
 * whole team can attach or provision buckets without re-pasting secrets.
 *
 * These are S3 access keys — distinct from {@see ProviderCredential}, which
 * holds the cloud platform API token. The secret is encrypted at rest.
 */
class ObjectStorageCredential extends Model
{
    use HasUlids;

    protected $table = 'object_storage_credentials';

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'provider',
        'name',
        'access_key_id',
        'secret_access_key',
        'region',
        'endpoint',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'secret_access_key' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdByUser(): BelongsTo {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
