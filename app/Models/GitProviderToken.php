<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\SourceControl\GitIdentity;
use App\Models\Concerns\AvoidsGitIdentityAttributeRecursion;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User-supplied Personal Access Token for a Git provider. Paired with
 * {@see SocialAccount} (OAuth) behind the {@see GitIdentity} contract so
 * the SourceControl service layer treats both kinds the same.
 */
class GitProviderToken extends Model implements GitIdentity
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
        'api_base_url',
        'last_validated_at',
    ];

    protected $hidden = [
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'last_validated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): string
    {
        if (! array_key_exists('access_token', $this->attributes)) {
            return '';
        }

        // The token is stored with the `encrypted` cast. When the ciphertext
        // can't be decrypted with the current APP_KEY (e.g. the key drifted
        // after a redeploy — see the prod seeding caveat), treat the identity
        // as unusable rather than letting a DecryptException 500 the whole
        // page that's merely listing repos/commits.
        try {
            $token = $this->castAttribute('access_token', $this->attributes['access_token']);
        } catch (DecryptException) {
            return '';
        }

        return trim((string) $token);
    }

    public function displayLabel(): string
    {
        $provider = ucfirst($this->provider());
        $name = trim((string) ($this->label ?: $this->nickname ?: $this->provider_id));

        return $provider.' token'.($name !== '' ? ' - '.$name : '');
    }

    public function apiBaseUrl(): string
    {
        $custom = trim((string) ($this->api_base_url ?? ''));
        if ($custom !== '') {
            return rtrim($custom, '/');
        }

        return match ($this->provider()) {
            'github' => 'https://api.github.com',
            'gitlab' => 'https://gitlab.com',
            'bitbucket' => 'https://api.bitbucket.org',
            default => '',
        };
    }

    public function kind(): string
    {
        return 'pat';
    }
}
