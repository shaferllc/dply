<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 *                      A reusable mail transport credential set scoped to an organization, so the
 *                      team can attach the same Mailgun/Postmark/SES/Resend/SMTP keys to multiple
 *                      sites without re-entering secrets each time.
 *                      The provider-specific fields (host/port/username/password for SMTP, secret +
 *                      domain for Mailgun, token for Postmark, AWS keys for SES, key for Resend)
 *                      live in the encrypted {@see $credentials} JSON column so one model handles
 *                      every provider shape. The from-address/name are deliberately NOT stored here
 *                      — they are per-site app identity, owned by the binding, not the credential.
 * @property ?string $created_by_user_id
 * @property array<string, mixed> $credentials
 * @property string $name
 * @property ?string $organization_id
 * @property string $provider
 * @property-read ?Organization $organization
 * @property-read ?User $createdByUser
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class MailCredential extends Model
{
    use HasUlids;

    protected $table = 'mail_credentials';

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'provider',
        'name',
        'credentials',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
