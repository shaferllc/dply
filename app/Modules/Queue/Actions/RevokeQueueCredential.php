<?php

declare(strict_types=1);

namespace App\Modules\Queue\Actions;

use App\Models\ServiceCredential;
use Illuminate\Support\Facades\Cache;

/**
 * Revoke a single queue credential, effective immediately.
 *
 * "Immediately" is the whole reason this product hashes with sha256. The
 * cache key is derived from `token_hash`, a column we already store, so this
 * can evict the exact entry without knowing the plaintext and without keeping
 * a side index of "which cache keys does this credential own". With a salted
 * hash none of that is possible and revocation degrades to waiting out a TTL.
 *
 * The 60s cache TTL remains as a backstop for a cache node that missed this
 * eviction — same reasoning as the queue pump's slot TTL: the fast path is
 * exact, and the slow path self-heals.
 */
final class RevokeQueueCredential
{
    public function handle(ServiceCredential $credential): ServiceCredential
    {
        if (! $credential->isRevoked()) {
            $credential->forceFill(['revoked_at' => now()])->save();
        }

        Cache::forget($credential->cacheKey());

        return $credential;
    }
}
