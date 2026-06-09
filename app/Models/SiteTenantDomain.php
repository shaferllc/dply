<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteTenantDomain extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'site_id',
        'hostname',
        'tenant_key',
        'label',
        'comment',
        'sort_order',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

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
        $meta = is_array($this->meta) ? $this->meta : [];

        return is_array($meta['testing'] ?? null) ? $meta['testing'] : [];
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
