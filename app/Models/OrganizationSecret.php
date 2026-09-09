<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationSecretFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * An org-scoped vault secret. Identity is the ULID — keys may collide.
 * The value is write-never in the UI; decrypt only at deploy / Cloud spec write.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $created_by_user_id
 * @property string $key
 * @property string $value
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User|null $createdBy
 *
 * @see docs/ORG_SHARED_SECRETS.md
 */
class OrganizationSecret extends Model
{
    /** @use HasFactory<OrganizationSecretFactory> */
    use HasFactory, HasUlids;

    protected static function newFactory(): OrganizationSecretFactory
    {
        return OrganizationSecretFactory::new();
    }

    protected $hidden = [
        'value',
    ];

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'key',
        'value',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsToMany<Site, $this> */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'organization_secret_sites')
            ->withPivot('key')
            ->withTimestamps();
    }

    /**
     * List/index query — never selects the ciphertext so Livewire snapshots
     * cannot leak a decrypted value.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForListing(Builder $query): Builder
    {
        return $query->select([
            'id',
            'organization_id',
            'created_by_user_id',
            'key',
            'notes',
            'created_at',
            'updated_at',
        ]);
    }
}
