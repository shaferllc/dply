<?php

namespace App\Models;

use App\Modules\SourceControl\Contracts\GitIdentity;
use App\Models\Concerns\AvoidsGitIdentityAttributeRecursion;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $access_token
 * @property ?\Illuminate\Support\Carbon $expires_at
 * @property string $label
 * @property ?\Illuminate\Support\Carbon $last_validated_at
 * @property string $nickname
 * @property string $provider
 * @property ?string $provider_id
 * @property string $refresh_token
 * @property ?string $user_id
 * @property ?string $validation_error
 * @property-read ?User $user
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SocialAccount extends Model implements GitIdentity
{
    use AvoidsGitIdentityAttributeRecursion;
    use HasUlids;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'label',
        'nickname',
        'access_token',
        'refresh_token',
        'last_validated_at',
        'expires_at',
        'validation_error',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_validated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): string
    {
        return trim((string) ($this->attributes['access_token'] ?? ''));
    }

    public function displayLabel(): string
    {
        $provider = ucfirst($this->provider());
        $nickname = trim((string) ($this->label ?: $this->nickname ?: $this->provider_id));

        return $provider.($nickname !== '' ? ' - '.$nickname : '');
    }

    public function apiBaseUrl(): string
    {
        return match ($this->provider()) {
            'github' => 'https://api.github.com',
            'gitlab' => 'https://gitlab.com',
            'bitbucket' => 'https://api.bitbucket.org',
            default => '',
        };
    }

    public function kind(): string
    {
        return 'oauth';
    }
}
