<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable OAuth / Socialite client credential set scoped to an organization
 * (GitHub / Google / Facebook / GitLab …). The client id + secret live in the
 * encrypted {@see $credentials} JSON column. Mirrors {@see ErrorTrackingCredential}.
 */
class OauthCredential extends Model
{
    use HasUlids;

    protected $table = 'oauth_credentials';

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'provider',
        'name',
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
