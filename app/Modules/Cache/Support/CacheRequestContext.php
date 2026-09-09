<?php

declare(strict_types=1);

namespace App\Modules\Cache\Support;

use App\Models\ServiceCredential;

/**
 * The authenticated identity behind one cache API request.
 *
 * Note what this deliberately does NOT carry, in contrast to the queue's
 * equivalent: a resource. The queue has no namespace field on the wire, so its
 * namespace can only come from the credential. DynamoDB's protocol *does* name
 * the table in every request, so the cache is chosen by the client and merely
 * *authorised* by the grant map.
 *
 * That is the whole tenancy model, and it is why resolution lives in the
 * controller behind one helper rather than here: `TableName` selects, the grant
 * decides, and the two must be checked together on every operation — including
 * the batch ones, where a single request names several tables.
 * See docs/adr/dply-cache.md, decision 14.
 */
final readonly class CacheRequestContext
{
    public function __construct(
        public ServiceCredential $credential,
    ) {}

    public function organizationId(): string
    {
        return $this->credential->organization_id;
    }

    public function allows(string $cacheId, string $scope): bool
    {
        return $this->credential->allows(ServiceCredential::SERVICE_CACHE, $cacheId, $scope);
    }
}
