<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property ?string $comment
 * @property string $hostname
 * @property string $label
 * @property array<string, mixed> $meta
 * @property ?string $site_id
 * @property string $sort_order
 * @property string $tenant_key
 * @property-read ?Site $site
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SiteTenantDomain extends Model
{
    use HasUlids;

    protected $fillable = [
        'site_id',
        'hostname',
        'tenant_key',
        'label',
        'comment',
        'sort_order',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Provisioning details for this tenant's managed testing-domain hostname
     * (an auto-created subdomain on a dply testing zone so you can reach the app
     * as this tenant before the customer points their real DNS). Stored under
     * meta['testing'] so no extra columns are needed.
     *
     * @return array<string, mixed>
     */
    public function testingMeta(): array
    {
        $meta = $this->meta;
        $testing = $meta['testing'] ?? null;

        return is_array($testing) ? $testing : [];
    }

    public function testingHostname(): ?string
    {
        $hostname = strtolower(trim((string) ($this->testingMeta()['hostname'] ?? '')));

        return $hostname !== '' ? $hostname : null;
    }

    public function testingDnsStatus(): ?string
    {
        $status = $this->testingMeta()['dns_status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }
}
