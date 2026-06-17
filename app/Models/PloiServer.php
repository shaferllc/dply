<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $ip_address
 * @property ?Carbon $last_synced_at
 * @property string $name
 * @property array<string, mixed> $php_versions
 * @property ?string $provider_credential_id
 * @property string $provider_label
 * @property bool $removed_from_source
 * @property string $server_type
 * @property int $source_id
 * @property array<string, mixed> $source_snapshot
 * @property string $status
 * @property-read ?ProviderCredential $providerCredential
 * @property-read Collection<int, PloiSite> $sites
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class PloiServer extends Model
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
    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class);
    }

    /** @return HasMany<PloiSite, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(PloiSite::class);
    }
}
