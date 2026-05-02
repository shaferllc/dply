<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SitePreviewDomain extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'site_id',
        'hostname',
        'label',
        'zone',
        'record_name',
        'provider_type',
        'provider_record_id',
        'record_type',
        'record_data',
        'dns_status',
        'ssl_status',
        'is_primary',
        'auto_ssl',
        'https_redirect',
        'managed_by_dply',
        'last_dns_checked_at',
        'last_ssl_checked_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'auto_ssl' => 'boolean',
            'https_redirect' => 'boolean',
            'managed_by_dply' => 'boolean',
            'last_dns_checked_at' => 'datetime',
            'last_ssl_checked_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(SiteCertificate::class, 'preview_domain_id');
    }
}
