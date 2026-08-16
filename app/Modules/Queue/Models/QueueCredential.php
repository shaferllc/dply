<?php

declare(strict_types=1);

namespace App\Modules\Queue\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 *                      A credential for a dply Queue namespace. The plaintext is shown to the
 *                      operator exactly once; only the sha256 hash and a short prefix persist.
 *                      sha256 rather than bcrypt, deliberately — see mint(). Two credentials may
 *                      be live per namespace so a rotation does not require an outage.
 * @property ?string $created_by_user_id
 * @property ?Carbon $expires_at
 * @property ?Carbon $last_used_at
 * @property string $name
 * @property string $namespace_id
 * @property ?string $organization_id
 * @property ?Carbon $revoked_at
 * @property array<int, string>|null $scopes
 * @property string $token_hash
 * @property string $token_prefix
 * @property-read ?QueueNamespace $queueNamespace
 * @property-read ?Organization $organization
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class QueueCredential extends Model
{
    use HasUlids;

    public const SCOPE_PUSH = 'push';

    public const SCOPE_POP = 'pop';

    /** How long a resolved credential stays cached. Self-healing backstop. */
    public const CACHE_TTL_SECONDS = 60;

    /**
     * `last_used_at` is written at most this often per credential. At pop
     * frequency an unconditional touch would make this a hot row taking
     * thousands of updates a minute.
     */
    public const LAST_USED_THROTTLE_SECONDS = 60;

    /** Explicit — inference would give `queue_credentials`. */
    protected $table = 'dply_queue_credentials';

    protected $fillable = [
        'namespace_id',
        'organization_id',
        'name',
        'token_prefix',
        'token_hash',
        'secret',
        'scopes',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'created_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Encrypted, not hashed: SigV4 is an HMAC over a shared secret, so
            // the server has to be able to recompute it. Same tradeoff as
            // RealtimeApp::app_secret, for the same reason. The hash below is
            // retained for lookup, caching, and the native bearer path.
            'secret' => 'encrypted',
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<QueueNamespace, $this> */
    public function queueNamespace(): BelongsTo
    {
        return $this->belongsTo(QueueNamespace::class, 'namespace_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Mint a credential, returning the plaintext exactly once.
     *
     * sha256, not bcrypt. A slow KDF exists to defend low-entropy, human-chosen
     * secrets against offline brute force; a 48-character CSPRNG string has
     * nothing to brute force. What bcrypt would cost is the invalidation
     * design: its hash is salted, so no cache key can be derived from the
     * stored row and a revoked credential could only be aged out by TTL. With
     * sha256 the cache key IS the stored column, so revocation is an exact
     * single-key eviction. Same reasoning as `EdgeDeployHook`.
     *
     * Returns the access key id and the secret — the pair a client puts in
     * AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY. The secret is shown once.
     *
     * @param  list<string>  $scopes
     * @return array{credential: self, plaintext: string}
     */
    public static function mint(
        QueueNamespace $namespace,
        string $name,
        array $scopes = [self::SCOPE_PUSH, self::SCOPE_POP],
        ?string $userId = null,
        ?Carbon $expiresAt = null,
    ): array {
        // The prefix doubles as the public access key id — it is what a client
        // puts in AWS_ACCESS_KEY_ID, and what the server looks the credential
        // up by. Unique and indexed, so resolution is one probe.
        $accessKeyId = 'dplyq'.Str::lower(Str::random(15));
        $plaintext = Str::random(48);

        $credential = self::query()->create([
            'namespace_id' => $namespace->id,
            'organization_id' => $namespace->organization_id,
            'name' => trim($name) !== '' ? trim($name) : __('Queue credential'),
            'token_prefix' => $accessKeyId,
            'token_hash' => self::hash($plaintext),
            'secret' => $plaintext,
            'scopes' => array_values(array_unique($scopes)),
            'expires_at' => $expiresAt,
            'created_by_user_id' => $userId,
        ]);

        return ['credential' => $credential, 'plaintext' => $plaintext];
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /** The cache key for a presented plaintext, derived from its hash alone. */
    public static function cacheKeyForHash(string $tokenHash): string
    {
        return 'dplyq:cred:'.$tokenHash;
    }

    public function cacheKey(): string
    {
        return self::cacheKeyForHash($this->token_hash);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * An empty scope list means both — a credential minted before scopes
     * existed, or one deliberately created unrestricted.
     */
    public function allows(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return $scopes === [] || in_array($scope, $scopes, true);
    }

    /**
     * The public access key id — what the client sends as
     * AWS_ACCESS_KEY_ID, and what the server resolves the credential by.
     * Safe to display in full; it is an identifier, not a secret.
     */
    public function accessKeyId(): string
    {
        return $this->token_prefix;
    }

    /** Display form: enough to identify a credential, never enough to use it. */
    public function maskedToken(): string
    {
        return $this->token_prefix.str_repeat('•', 12);
    }
}
