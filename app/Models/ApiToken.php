<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'name',
        'token_prefix',
        'token_hash',
        'last_used_at',
        'expires_at',
        'abilities',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'abilities' => 'array',
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

    /**
     * Create a new token and return the plaintext value (only time it is visible).
     */
    public static function createToken(
        User $user,
        Organization $organization,
        string $name,
        ?\DateTimeInterface $expiresAt = null,
        ?array $abilities = null
    ): array {
        $plaintext = 'dply_'.Str::random(64);
        $prefix = substr($plaintext, 0, 16);

        $token = self::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => $name,
            'token_prefix' => $prefix,
            'token_hash' => bcrypt($plaintext),
            'expires_at' => $expiresAt,
            'abilities' => $abilities,
        ]);

        return ['token' => $token, 'plaintext' => $plaintext];
    }

    public function isValid(): bool
    {
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * @param  string|null  $ability  e.g. sites.deploy, servers.read, commands.run
     */
    public function allows(?string $ability = null): bool
    {
        $abilities = $this->abilities;

        if ($abilities === null || $abilities === []) {
            return true;
        }

        if (in_array('*', $abilities, true)) {
            return true;
        }

        if ($ability === null || $ability === '') {
            return false;
        }

        if (in_array($ability, $abilities, true)) {
            return true;
        }

        $prefix = explode('.', $ability, 2)[0] ?? '';

        return $prefix !== '' && in_array($prefix.'.*', $abilities, true);
    }

    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Find a token by the raw value from Authorization header.
     */
    public static function findTokenByPlaintext(string $plaintext): ?self
    {
        if (strlen($plaintext) < 16) {
            return null;
        }

        $prefix = substr($plaintext, 0, 16);
        $token = self::where('token_prefix', $prefix)->first();

        if (! $token || ! Hash::check($plaintext, $token->token_hash)) {
            return null;
        }

        return $token;
    }

    /**
     * Masked display (e.g. dply_abc12345...).
     */
    public function getMaskedDisplayAttribute(): string
    {
        return $this->token_prefix.'…';
    }
}
