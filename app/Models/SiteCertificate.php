<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property array<string, mixed> $applied_settings
 * @property string $certificate_path
 * @property string $certificate_pem
 * @property string $chain_path
 * @property string $chain_pem
 * @property string $challenge_type
 * @property string $credential_reference
 * @property string $csr_pem
 * @property string $dns_provider
 * @property array<string, mixed> $domains_json
 * @property bool $enable_http3
 * @property ?Carbon $expires_at
 * @property bool $force_skip_dns_checks
 * @property ?Carbon $last_installed_at
 * @property string $last_output
 * @property ?Carbon $last_requested_at
 * @property array<string, mixed> $meta
 * @property ?string $preview_domain_id
 * @property string $private_key_path
 * @property string $private_key_pem
 * @property ?string $provider_credential_id
 * @property string $provider_type
 * @property array<string, mixed> $requested_settings
 * @property string $scope_type
 * @property ?string $site_id
 * @property string $status
 * @property-read ?Site $site
 * @property-read ?SitePreviewDomain $previewDomain
 * @property-read ?ProviderCredential $providerCredential
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SiteCertificate extends Model
{
    use HasUlids;

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

    /** @return array<string, string> */
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

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<SitePreviewDomain, $this> */
    public function previewDomain(): BelongsTo
    {
        return $this->belongsTo(SitePreviewDomain::class, 'preview_domain_id');
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class);
    }

    /**
     * @return list<string>
     */
    public function domainHostnames(): array
    {
        return collect($this->domains_json)
            ->filter(fn (mixed $hostname): bool => is_string($hostname) && trim($hostname) !== '')
            ->map(fn (string $hostname): string => strtolower(trim($hostname)))
            ->unique()
            ->values()
            ->all();
    }
}
