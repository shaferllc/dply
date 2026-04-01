<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteCertificate extends Model
{
    use HasFactory, HasUlids;

    public const SCOPE_CUSTOMER = 'customer';
    public const SCOPE_PREVIEW = 'preview';

    public const PROVIDER_LETSENCRYPT = 'letsencrypt';
    public const PROVIDER_ZEROSSL = 'zerossl';
    public const PROVIDER_IMPORTED = 'imported';
    public const PROVIDER_CSR = 'csr';

    public const CHALLENGE_HTTP = 'http';
    public const CHALLENGE_DNS = 'dns';
    public const CHALLENGE_MANUAL = 'manual';
    public const CHALLENGE_IMPORTED = 'imported';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_INSTALLING = 'installing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'site_id',
        'preview_domain_id',
        'provider_credential_id',
        'scope_type',
        'provider_type',
        'challenge_type',
        'dns_provider',
        'credential_reference',
        'domains_json',
        'status',
        'force_skip_dns_checks',
        'enable_http3',
        'certificate_path',
        'private_key_path',
        'chain_path',
        'certificate_pem',
        'private_key_pem',
        'chain_pem',
        'csr_pem',
        'last_output',
        'expires_at',
        'last_requested_at',
        'last_installed_at',
        'requested_settings',
        'applied_settings',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'domains_json' => 'array',
            'force_skip_dns_checks' => 'boolean',
            'enable_http3' => 'boolean',
            'certificate_pem' => 'encrypted',
            'private_key_pem' => 'encrypted',
            'chain_pem' => 'encrypted',
            'csr_pem' => 'encrypted',
            'requested_settings' => 'array',
            'applied_settings' => 'array',
            'meta' => 'array',
            'expires_at' => 'datetime',
            'last_requested_at' => 'datetime',
            'last_installed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function previewDomain(): BelongsTo
    {
        return $this->belongsTo(SitePreviewDomain::class, 'preview_domain_id');
    }

    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class);
    }

    /**
     * @return list<string>
     */
    public function domainHostnames(): array
    {
        return collect(is_array($this->domains_json) ? $this->domains_json : [])
            ->filter(fn (mixed $hostname): bool => is_string($hostname) && trim($hostname) !== '')
            ->map(fn (string $hostname): string => strtolower(trim($hostname)))
            ->unique()
            ->values()
            ->all();
    }
}
