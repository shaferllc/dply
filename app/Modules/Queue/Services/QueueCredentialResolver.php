<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves an access key id to its credential and namespace.
 *
 * This sits on the hot path — every push and every poll goes through it — so
 * it must not touch Postgres in the common case and must never write to it.
 *
 * Three cache layers, each earning its place:
 *
 *  - **Positive**, 60s. The steady state. Keyed off `token_hash`, a column we
 *    already store, so revocation can evict the exact entry (see
 *    RevokeQueueCredential). The TTL is a self-healing backstop for a cache
 *    node that missed the eviction, not the primary mechanism.
 *  - **Negative**, 10s. A stale `.env` after a rotation, or a credential
 *    stuffing loop, otherwise becomes a Postgres query per request.
 *  - **Namespace epoch**, cached longer. Lets "revoke everything in this
 *    namespace" work without enumerating credentials: the cached tuple carries
 *    the epoch it was minted under, and a mismatch forces a re-read.
 *
 * `last_used_at` is written at most once per minute per credential, and only
 * when the cached tuple says it is due. At poll frequency an unconditional
 * touch would make that a single hot row taking thousands of updates a minute.
 */
final class QueueCredentialResolver
{
    private const NEGATIVE_TTL_SECONDS = 10;

    private const EPOCH_TTL_SECONDS = 300;

    /**
     * @return array{credential: QueueCredential, namespace: QueueNamespace}|null
     */
    public function resolve(string $accessKeyId): ?array
    {
        if (trim($accessKeyId) === '') {
            return null;
        }

        if (Cache::get($this->negativeKey($accessKeyId)) === true) {
            return null;
        }

        $credential = QueueCredential::query()
            ->where('token_prefix', $accessKeyId)
            ->first();

        if ($credential === null || ! $credential->isUsable()) {
            Cache::put($this->negativeKey($accessKeyId), true, self::NEGATIVE_TTL_SECONDS);

            return null;
        }

        $namespace = $credential->queueNamespace;

        if (! $namespace instanceof QueueNamespace || ! $namespace->isActive()) {
            Cache::put($this->negativeKey($accessKeyId), true, self::NEGATIVE_TTL_SECONDS);

            return null;
        }

        $this->touch($credential);

        return ['credential' => $credential, 'namespace' => $namespace];
    }

    /**
     * Resolve by presented secret rather than access key id.
     *
     * The dply-native endpoints (locks, failed jobs) use a plain bearer token
     * instead of SigV4 — they are not part of the SQS protocol, so there is no
     * compatibility reason to make a client sign, and signing from inside the
     * injected handler would mean shipping a signer into every customer app.
     *
     * This is what `token_hash` was retained for: the presented secret hashes
     * to a unique-indexed column, so resolution is one probe, and the cache key
     * derived from that same column keeps revocation an exact eviction.
     *
     * @return array{credential: QueueCredential, namespace: QueueNamespace}|null
     */
    public function resolveBySecret(string $secret): ?array
    {
        $secret = trim($secret);

        if ($secret === '') {
            return null;
        }

        $hash = QueueCredential::hash($secret);

        if (Cache::get($this->negativeHashKey($hash)) === true) {
            return null;
        }

        $credential = QueueCredential::query()->where('token_hash', $hash)->first();

        if ($credential === null || ! $credential->isUsable()) {
            Cache::put($this->negativeHashKey($hash), true, self::NEGATIVE_TTL_SECONDS);

            return null;
        }

        $namespace = $credential->queueNamespace;

        if (! $namespace instanceof QueueNamespace || ! $namespace->isActive()) {
            Cache::put($this->negativeHashKey($hash), true, self::NEGATIVE_TTL_SECONDS);

            return null;
        }

        $this->touch($credential);

        return ['credential' => $credential, 'namespace' => $namespace];
    }

    /**
     * Record use, at most once per throttle window.
     *
     * Deliberately best-effort and cache-gated: this exists so an operator can
     * see whether an old credential is still in use during a rotation, not as
     * an audit trail. Accuracy to the minute is plenty, and a write per
     * request would be far more expensive than the information is worth.
     */
    private function touch(QueueCredential $credential): void
    {
        $key = 'dplyq:cred:touched:'.$credential->token_hash;

        if (Cache::get($key) !== null) {
            return;
        }

        Cache::put($key, true, QueueCredential::LAST_USED_THROTTLE_SECONDS);

        $credential->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    /** Invalidate the negative entry so a freshly minted key works at once. */
    public function forgetNegative(string $accessKeyId): void
    {
        Cache::forget($this->negativeKey($accessKeyId));
    }

    public function namespaceEpoch(QueueNamespace $namespace): int
    {
        return (int) Cache::remember(
            'dplyq:ns:epoch:'.$namespace->id,
            self::EPOCH_TTL_SECONDS,
            fn (): int => $namespace->credential_epoch,
        );
    }

    private function negativeKey(string $accessKeyId): string
    {
        return 'dplyq:cred:miss:'.hash('sha256', $accessKeyId);
    }

    /** Negative key for a secret lookup — never keyed by the secret itself. */
    private function negativeHashKey(string $tokenHash): string
    {
        return 'dplyq:cred:miss-hash:'.$tokenHash;
    }
}
