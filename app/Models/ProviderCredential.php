<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Http;

class ProviderCredential extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'organization_id',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'provider_credential_id');
    }

    public function getApiToken(): ?string
    {
        if ($this->provider === 'digitalocean') {
            $this->refreshDigitalOceanOAuthTokenIfExpired();
        }

        $creds = $this->credentials ?? [];

        return $creds['api_token'] ?? $creds['access_token'] ?? $creds['token'] ?? null;
    }

    /**
     * Refresh DigitalOcean OAuth access token when near expiry (keeps Bearer API calls working).
     */
    public function refreshDigitalOceanOAuthTokenIfExpired(): void
    {
        if ($this->provider !== 'digitalocean') {
            return;
        }

        $creds = $this->credentials ?? [];
        if (($creds['auth'] ?? '') !== 'oauth') {
            return;
        }

        $expiresAtRaw = $creds['expires_at'] ?? null;
        $expiresAt = is_string($expiresAtRaw) ? Carbon::parse($expiresAtRaw) : null;
        $refreshToken = $creds['refresh_token'] ?? null;

        if ($expiresAt instanceof CarbonInterface && now()->addSeconds(120)->lt($expiresAt)) {
            return;
        }

        if (! is_string($refreshToken) || $refreshToken === '') {
            return;
        }

        $clientId = config('services.digitalocean_oauth.client_id');
        $clientSecret = config('services.digitalocean_oauth.client_secret');
        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            return;
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post('https://cloud.digitalocean.com/v1/oauth/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        if (! $response->successful()) {
            return;
        }

        $access = $response->json('access_token');
        if (! is_string($access) || $access === '') {
            return;
        }

        $newRefresh = $response->json('refresh_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        $this->credentials = array_merge($creds, [
            'access_token' => $access,
            'refresh_token' => is_string($newRefresh) && $newRefresh !== '' ? $newRefresh : $refreshToken,
            'expires_at' => now()->addSeconds(max(60, $expiresIn))->toIso8601String(),
        ]);
        $this->save();
    }
}
