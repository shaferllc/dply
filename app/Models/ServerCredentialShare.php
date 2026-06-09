<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A short-lived, reveal-once link that lets the recipient view a server's
 * credentials (currently the dedicated cache/redis AUTH block) without the
 * secret ever being placed in an email body.
 *
 * Mirrors {@see ServerDatabaseCredentialShare}: token + expiry + a decrementing
 * view counter. The secret itself is NOT stored here — it is decrypted from the
 * server meta at view time (see ServerCredentialShareController).
 */
class ServerCredentialShare extends Model
{
    use HasUlids;

    public const KIND_REDIS = 'redis';

    protected $table = 'server_credential_shares';

    protected $fillable = [
        'server_id',
        'user_id',
        'kind',
        'token',
        'expires_at',
        'views_remaining',
        'max_views',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->views_remaining < 1;
    }

    /**
     * Issue a fresh reveal-once link for a server's credentials.
     */
    public static function issue(
        Server $server,
        User $user,
        string $kind = self::KIND_REDIS,
        int $expiresHours = 48,
        int $maxViews = 3,
    ): self {
        return static::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'kind' => $kind,
            'token' => Str::random(48),
            'expires_at' => now()->addHours($expiresHours),
            'views_remaining' => $maxViews,
            'max_views' => $maxViews,
        ]);
    }
}
