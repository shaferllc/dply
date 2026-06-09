<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdgeSiteAccessRule extends Model
{
    use HasUlids;

    public const MODE_OFF = 'off';

    public const MODE_PASSWORD = 'password';

    public const MODE_DPLY_ACCOUNT = 'dply_account';

    protected $fillable = [
        'site_id',
        'mode',
        'password_hash',
        'password_salt',
        'password_verifier',
        'cookie_secret',
        'allowed_emails',
    ];

    protected function casts(): array
    {
        return [
            'allowed_emails' => 'array',
            'password_hash' => 'hashed',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isEnabled(): bool
    {
        return $this->mode !== self::MODE_OFF;
    }

    /**
     * @return list<string>
     */
    public function normalizedAllowedEmails(): array
    {
        $emails = is_array($this->allowed_emails) ? $this->allowed_emails : [];

        return array_values(array_unique(array_filter(array_map(
            static fn ($email): string => is_string($email) ? strtolower(trim($email)) : '',
            $emails,
        ))));
    }
}
