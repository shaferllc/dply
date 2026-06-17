<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * A reusable CAPTCHA provider credential set scoped to an organization
 * (reCAPTCHA / Turnstile / hCaptcha). The site key + secret live in the
 * encrypted {@see $credentials} JSON column. Mirrors {@see ErrorTrackingCredential}.
 */
class CaptchaCredential extends Model
{
    use HasUlids;

    protected $table = 'captcha_credentials';

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
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdByUser(): BelongsTo {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
