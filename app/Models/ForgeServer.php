<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 */

class ForgeServer extends Model
{
    use HasUlids;

    protected $fillable = [
        'provider_credential_id',
        'source_id',
        'name',
        'ip_address',
        'provider_label',
        'server_type',
        'php_versions',
        'status',
        'last_synced_at',
        'removed_from_source',
        'source_snapshot',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'php_versions' => 'array',
            'source_snapshot' => 'array',
            'last_synced_at' => 'datetime',
            'removed_from_source' => 'boolean',
        ];
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function providerCredential(): BelongsTo {
        return $this->belongsTo(ProviderCredential::class);
    }

    /** @return HasMany<ForgeSite, $this> */
    public function sites(): HasMany {
        return $this->hasMany(ForgeSite::class);
    }
}
