<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An access key for dply's AWS-compatible managed services.
 *
 * One key pair, many grants. This is not a generalisation for its own sake:
 * Laravel's stock `sqs` queue store and `dynamodb` cache store both read
 * AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY, so an app using dply Queue and
 * dply Cache together *cannot* hold two pairs without editing config it owns.
 * See docs/adr/dply-cache.md, decision 6.
 *
 * The plaintext is shown exactly once. sha256 rather than bcrypt, deliberately
 * — see {@see mint()}.
 *
 * @property string $id
 * @property ?string $created_by_user_id
 * @property ?Carbon $expires_at
 * @property array<string, list<string>> $grants
 * @property ?Carbon $last_used_at
 * @property string $name
 * @property string $organization_id
 * @property ?Carbon $revoked_at
 * @property ?string $secret
 * @property string $token_hash
 * @property string $token_prefix
 * @property-read ?Organization $organization
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ServiceCredential extends Model
{
    use HasUlids;

    /** Services a grant may name. The SigV4 credential scope carries the same token. */
    public const SERVICE_QUEUE = 'queue';

    public const SERVICE_CACHE = 'cache';

    /** Queue scopes. */
    public const SCOPE_PUSH = 'push';

    public const SCOPE_POP = 'pop';

    /** Cache scopes. */
    public const SCOPE_READ = 'read';

    public const SCOPE_WRITE = 'write';

    /** How long a resolved credential stays cached. Self-healing backstop. */
    public const CACHE_TTL_SECONDS = 60;

    /**
     * `last_used_at` is written at most this often per credential. At pop
     * frequency an unconditional touch would make this a hot row taking
     * thousands of updates a minute.
     */
    public const LAST_USED_THROTTLE_SECONDS = 60;

    protected $fillable = [
        'organization_id',
        'name',
        'token_prefix',
        'token_hash',
        'secret',
        'grants',
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
            // the server has to recompute it. The hash is retained for lookup,
            // cache-key derivation, and the native bearer path.
            'secret' => 'encrypted',
            'grants' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The grant map key for one resource. `queue:01J…` / `cache:01K…`.
     */
    public static function grantKey(string $service, string $resourceId): string
    {
        return $service.':'.$resourceId;
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
     * @param  array<string, list<string>>  $grants  keyed by {@see grantKey()}
     * @return array{credential: self, plaintext: string}
     */
    public static function mint(
        string $organizationId,
        string $name,
        array $grants,
        ?string $userId = null,
        ?Carbon $expiresAt = null,
    ): array {
        // The prefix doubles as the public access key id — what a client puts
        // in AWS_ACCESS_KEY_ID and what the server looks the credential up by.
        // Unique and indexed, so resolution is one probe.
        $accessKeyId = 'dply'.Str::lower(Str::random(16));
        $plaintext = Str::random(48);

        $credential = self::query()->create([
            'organization_id' => $organizationId,
            'name' => trim($name) !== '' ? trim($name) : __('Service credential'),
            'token_prefix' => $accessKeyId,
            'token_hash' => self::hash($plaintext),
            'secret' => $plaintext,
            'grants' => self::normalizeGrants($grants),
            'expires_at' => $expiresAt,
            'created_by_user_id' => $userId,
        ]);

        return ['credential' => $credential, 'plaintext' => $plaintext];
    }

    /**
     * @param  array<string, list<string>>  $grants
     * @return array<string, list<string>>
     */
    private static function normalizeGrants(array $grants): array
    {
        $normalized = [];

        foreach ($grants as $key => $scopes) {
            $normalized[(string) $key] = array_values(array_unique(array_map('strval', $scopes)));
        }

        return $normalized;
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /** The cache key for a presented plaintext, derived from its hash alone. */
    public static function cacheKeyForHash(string $tokenHash): string
    {
        return 'dplysc:cred:'.$tokenHash;
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
     * Every resource id this key is granted on for one service.
     *
     * @return list<string>
     */
    public function resourceIds(string $service): array
    {
        $prefix = $service.':';
        $ids = [];

        foreach (array_keys($this->grants ?? []) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                $ids[] = substr((string) $key, strlen($prefix));
            }
        }

        return $ids;
    }

    /** Whether this key names the resource at all, regardless of scope. */
    public function grantsResource(string $service, string $resourceId): bool
    {
        return array_key_exists(self::grantKey($service, $resourceId), $this->grants ?? []);
    }

    /**
     * Whether this key may perform `$scope` on the named resource.
     *
     * An empty scope list means "every scope on this resource" — a grant
     * deliberately created unrestricted, and the shape a credential minted
     * before scopes existed migrates to. Absence of the key is always a no:
     * a missing grant is never an implicit allow.
     */
    public function allows(string $service, string $resourceId, string $scope): bool
    {
        $scopes = ($this->grants ?? [])[self::grantKey($service, $resourceId)] ?? null;

        if (! is_array($scopes)) {
            return false;
        }

        return $scopes === [] || in_array($scope, $scopes, true);
    }

    /**
     * Credentials holding any grant on one resource.
     *
     * Uses jsonb key-existence (`?`), which the GIN index on `grants` serves.
     * The operator is escaped as `??` because PDO would otherwise read a lone
     * `?` as a positional bind placeholder — a genuinely confusing failure,
     * since the query parses fine and simply binds the wrong number of values.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForResource(Builder $query, string $service, string $resourceId): Builder
    {
        return $query->whereRaw('grants ?? ?', [self::grantKey($service, $resourceId)]);
    }

    /**
     * The public access key id — what the client sends as AWS_ACCESS_KEY_ID.
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
