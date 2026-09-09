<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An operator IP dply added to a managed cluster's trusted-source list.
 *
 * dply-tracked entries are the *only* ones the reaper may remove. Anything else
 * on the provider's list — the app server rule, or an address a customer added
 * in the provider console — is preserved on every write.
 *
 * @property string $id
 * @property string $cloud_database_id
 * @property string $ip_address
 * @property ?string $created_by_user_id
 * @property Carbon $expires_at
 * @property ?Carbon $revoked_at
 * @property-read ?CloudDatabase $cloudDatabase
 * @property-read ?User $createdBy
 */
class CloudDatabaseTrustedSource extends Model
{
    use HasUlids;

    protected $fillable = [
        'cloud_database_id',
        'ip_address',
        'created_by_user_id',
        'expires_at',
        'revoked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CloudDatabase, $this> */
    public function cloudDatabase(): BelongsTo
    {
        return $this->belongsTo(CloudDatabase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Still on the provider's list as far as dply is concerned. */
    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /** @param  Builder<self>  $query */
    public function scopeReapable(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '<=', now());
    }
}
