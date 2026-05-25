<?php

namespace App\Models;

use App\Contracts\SourceControl\GitIdentity;
use App\Models\Concerns\AvoidsGitIdentityAttributeRecursion;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

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
