<?php

declare(strict_types=1);

namespace App\Modules\Cache\Actions;

use App\Models\ServiceCredential;
use App\Modules\Cache\Models\ManagedCache;
use Illuminate\Support\Carbon;

/**
 * Mint an access key granted on one cache.
 *
 * The single place the cache's grant shape is written — the counterpart to
 * {@see \App\Modules\Queue\Actions\MintQueueCredential}, and separate from it
 * because the two modules must not depend on each other.
 *
 * Unlike the queue's, a key may hold several cache grants: DynamoDB names the
 * table in every request, so several caches on one key are addressable rather
 * than ambiguous.
 */
final class MintCacheCredential
{
    /**
     * @param  list<string>  $scopes
     * @return array{credential: ServiceCredential, plaintext: string}
     */
    public function handle(
        ManagedCache $cache,
        string $name,
        array $scopes = [ServiceCredential::SCOPE_READ, ServiceCredential::SCOPE_WRITE],
        ?string $userId = null,
        ?Carbon $expiresAt = null,
    ): array {
        return ServiceCredential::mint(
            organizationId: $cache->organization_id,
            name: $name,
            grants: [
                ServiceCredential::grantKey(ServiceCredential::SERVICE_CACHE, $cache->id) => $scopes,
            ],
            userId: $userId,
            expiresAt: $expiresAt,
        );
    }
}
