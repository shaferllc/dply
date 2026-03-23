<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderCredential extends Model
{
    use HasFactory;

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
        $creds = $this->credentials;
        return $creds['api_token'] ?? $creds['token'] ?? null;
    }
}
