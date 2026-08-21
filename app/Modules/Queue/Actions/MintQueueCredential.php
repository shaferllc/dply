<?php

declare(strict_types=1);

namespace App\Modules\Queue\Actions;

use App\Models\ServiceCredential;
use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Support\Carbon;

/**
 * Mint an access key granted on one queue namespace.
 *
 * The single place the queue's grant shape is written. Credentials became
 * org-owned keys carrying a grant map (docs/adr/dply-cache.md, decision 6), so
 * "a credential for this namespace" is no longer a column but a convention —
 * and a convention spelled out at four call sites is one typo away from a key
 * that authenticates and then authorises nothing.
 *
 * Exactly one queue grant per key, deliberately: `AuthenticateQueueCredential`
 * derives the namespace from the grant map because the SQS wire format has no
 * namespace field, and refuses a key that names two.
 */
final class MintQueueCredential
{
    /**
     * @param  list<string>  $scopes
     * @return array{credential: ServiceCredential, plaintext: string}
     */
    public function handle(
        QueueNamespace $namespace,
        string $name,
        array $scopes = [ServiceCredential::SCOPE_PUSH, ServiceCredential::SCOPE_POP],
        ?string $userId = null,
        ?Carbon $expiresAt = null,
    ): array {
        return ServiceCredential::mint(
            organizationId: (string) $namespace->organization_id,
            name: $name,
            grants: [
                ServiceCredential::grantKey(ServiceCredential::SERVICE_QUEUE, $namespace->id) => $scopes,
            ],
            userId: $userId,
            expiresAt: $expiresAt,
        );
    }
}
