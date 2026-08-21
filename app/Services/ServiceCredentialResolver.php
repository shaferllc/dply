<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ServiceCredential;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves an access key id — or a presented secret — to its credential.
 *
 * Generalised out of the queue's resolver when one key pair started serving
 * two services (docs/adr/dply-cache.md, decision 6). Note what it deliberately
 * does *not* do any more: it resolves the credential and stops. Loading the
 * resource a request addresses is the caller's job, because the resource is
 * now chosen by the request (which queue, which cache) and merely *authorised*
 * by the grant map. Returning a resource from here would put the tenancy
 * decision in the wrong place.
 *
 * This sits on the hot path — every push and every cache read goes through it
 * — so it must not touch Postgres in the common case and must never write to
 * it.
 *
 * Caching is **negative only**, 10s. A stale `.env` after a rotation, or a
 * credential-stuffing loop, would otherwise cost a Postgres query per request;
 * a valid key costs one indexed probe on a unique column, which is cheap
 * enough not to be worth the invalidation surface.
 *
 * Note that this is what the queue's resolver actually did too, despite a
 * docblock there describing a positive layer it never implemented. The
 * eviction contract that layer would need is nonetheless real and preserved:
 * `ServiceCredential::cacheKey()` is derived from `token_hash` alone, so
 * revocation can evict an exact entry without knowing the plaintext — which is
 * the property the sha256-over-bcrypt choice exists to buy, and what a future
 * positive layer would be built on.
 *
 * `last_used_at` is written at most once per minute per credential. At poll
 * frequency an unconditional touch would make that a single hot row taking
 * thousands of updates a minute.
 */
final class ServiceCredentialResolver
{
    private const NEGATIVE_TTL_SECONDS = 10;

    /**
     * Resolve by access key id — the SigV4 path.
     */
    public function resolve(string $accessKeyId): ?ServiceCredential
    {
        if (trim($accessKeyId) === '') {
            return null;
        }

        if (Cache::get($this->negativeKey($accessKeyId)) === true) {
            return null;
        }

        $credential = ServiceCredential::query()
            ->where('token_prefix', $accessKeyId)
            ->first();

        if (! $credential instanceof ServiceCredential || ! $credential->isUsable()) {
            Cache::put($this->negativeKey($accessKeyId), true, self::NEGATIVE_TTL_SECONDS);

            return null;
        }

        $this->touch($credential);

        return $credential;
    }

    /**
     * Resolve by presented secret rather than access key id.
     *
     * The dply-native endpoints (failed jobs) use a plain bearer token instead
     * of SigV4 — they are not part of any compatibility contract, so there is
     * no reason to make a client sign, and signing from inside the injected
     * handler would mean shipping a signer into every customer app.
     *
     * This is what `token_hash` is retained for: the presented secret hashes
     * to a unique-indexed column, so resolution is one probe, and the cache key
     * derived from that same column keeps revocation an exact eviction.
     */
    public function resolveBySecret(string $secret): ?ServiceCredential
    {
        $secret = trim($secret);

        if ($secret === '') {
            return null;
        }

        $hash = ServiceCredential::hash($secret);

        if (Cache::get($this->negativeHashKey($hash)) === true) {
            return null;
        }

        $credential = ServiceCredential::query()->where('token_hash', $hash)->first();

        if (! $credential instanceof ServiceCredential || ! $credential->isUsable()) {
            Cache::put($this->negativeHashKey($hash), true, self::NEGATIVE_TTL_SECONDS);

            return null;
        }

        $this->touch($credential);

        return $credential;
    }

    /**
     * Record use, at most once per throttle window.
     *
     * Deliberately best-effort and cache-gated: this exists so an operator can
     * see whether an old credential is still in use during a rotation, not as
     * an audit trail. Accuracy to the minute is plenty, and a write per
     * request would be far more expensive than the information is worth.
     */
    private function touch(ServiceCredential $credential): void
    {
        $key = 'dplysc:cred:touched:'.$credential->token_hash;

        if (Cache::get($key) !== null) {
            return;
        }

        Cache::put($key, true, ServiceCredential::LAST_USED_THROTTLE_SECONDS);

        $credential->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    /** Invalidate the negative entry so a freshly minted key works at once. */
    public function forgetNegative(string $accessKeyId): void
    {
        Cache::forget($this->negativeKey($accessKeyId));
    }

    private function negativeKey(string $accessKeyId): string
    {
        return 'dplysc:cred:miss:'.hash('sha256', $accessKeyId);
    }

    /** Negative key for a secret lookup — never keyed by the secret itself. */
    private function negativeHashKey(string $tokenHash): string
    {
        return 'dplysc:cred:miss-hash:'.$tokenHash;
    }
}
