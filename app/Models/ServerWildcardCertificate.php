<?php

namespace App\Models;

use App\Modules\Certificates\Services\WildcardCertificateIssuer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      A wildcard TLS certificate (e.g. *.on-dply.com) issued via DNS-01 and
 *                      installed on a single server, shared by every testing-hostname site on that
 *                      server/zone. See the create_server_wildcard_certificates migration and
 *                      {@see WildcardCertificateIssuer}.
 * @property string $cert_path
 * @property string $key_path
 * @property ?Carbon $last_installed_at
 * @property string $last_output
 * @property ?Carbon $last_renewed_at
 * @property ?Carbon $last_requested_at
 * @property string $live_directory
 * @property array<string, mixed> $meta
 * @property ?Carbon $not_after
 * @property string $provider
 * @property ?string $provider_credential_id
 * @property ?string $server_id
 * @property string $status
 * @property string $zone
 * @property-read ?Server $server
 * @property-read ?ProviderCredential $providerCredential
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerWildcardCertificate extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ISSUING = 'issuing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'server_id',
        'zone',
        'provider',
        'provider_credential_id',
        'status',
        'live_directory',
        'cert_path',
        'key_path',
        'not_after',
        'last_requested_at',
        'last_renewed_at',
        'last_installed_at',
        'last_output',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'not_after' => 'datetime',
            'last_requested_at' => 'datetime',
            'last_renewed_at' => 'datetime',
            'last_installed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class);
    }

    /**
     * True when the cert is issued, installed on disk, and usable by a vhost.
     */
    public function isInstalled(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->last_installed_at !== null;
    }

    /**
     * Whether the cert needs (re)issuance: missing, failed, expired, or within
     * the renewal window of its expiry.
     */
    public function needsIssuance(int $renewWithinDays = 30): bool
    {
        if ($this->status !== self::STATUS_ACTIVE || $this->last_installed_at === null) {
            return true;
        }

        $notAfter = $this->not_after;
        if ($notAfter === null) {
            return false;
        }

        return $notAfter->isBefore(now()->addDays($renewWithinDays));
    }
}
