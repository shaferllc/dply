<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single-use claim check tying "someone clicked Connect Telegram" to the
 * `/start` that arrives moments later from Telegram's servers.
 *
 * This exists because the Telegram connect flow has no redirect back: the
 * operator leaves the browser for the Telegram app, picks a chat there, and the
 * only evidence linking that chat to their dply account is this payload riding
 * in the deep link. Which makes the token a bearer credential — anyone who
 * obtains it before it is consumed could attach their own chat to the issuing
 * account, hence single-use and short-lived.
 *
 * @property string $id
 * @property string $token
 * @property string $owner_type
 * @property string $owner_id
 * @property ?string $user_id
 * @property ?Carbon $consumed_at
 * @property ?string $installation_id
 * @property Carbon $expires_at
 */
class TelegramConnectToken extends Model
{
    use HasUlids;

    /**
     * Long enough that guessing is hopeless, short enough for Telegram's 64-char
     * start-payload cap. The charset is deliberately restricted to what Telegram
     * accepts in a start parameter (A-Za-z0-9_-).
     */
    private const TOKEN_LENGTH = 40;

    /** Minutes a link stays usable — enough to switch apps and pick a chat, not enough to sit in a chat log. */
    public const TTL_MINUTES = 15;

    protected $fillable = [
        'token',
        'owner_type',
        'owner_id',
        'user_id',
        'consumed_at',
        'installation_id',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public static function generateToken(): string
    {
        // Str::random can emit characters Telegram rejects in a start payload,
        // so the alphabet is pinned rather than filtered after the fact.
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $out = '';
        for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    public function isRedeemable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /**
     * @param  Builder<TelegramConnectToken>  $query
     * @return Builder<TelegramConnectToken>
     */
    public function scopeRedeemable(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    public static function purgeExpired(): void
    {
        static::query()->where('expires_at', '<', now()->subDay())->delete();
    }

    public static function issueFor(Model $owner, ?User $user): self
    {
        return static::query()->create([
            'token' => static::generateToken(),
            'owner_type' => $owner::class,
            'owner_id' => (string) $owner->getKey(),
            'user_id' => $user?->id,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
    }
}
