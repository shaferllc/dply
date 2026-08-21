<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Support\QueueEndpoint;
use RuntimeException;

/**
 * The env a managed worker needs to reach its own queue.
 *
 * Deliberately the same four keys `ServerlessQueueProvisioner` writes into a
 * deployed app: a dply-owned worker authenticates over the public
 * SQS-compatible endpoint exactly like a customer's own app would. Giving the
 * managed path a private shortcut into the store would mean the endpoint
 * customers depend on is the one dply itself never exercises.
 */
class FleetWorkerEnvironment
{
    /**
     * @return array<string, string>
     *
     * @throws RuntimeException when dply has no publicly reachable endpoint
     */
    public function for(ManagedQueueFleet $fleet): array
    {
        $namespace = $fleet->namespace;

        if (! $namespace instanceof QueueNamespace) {
            throw new RuntimeException('Fleet '.$fleet->id.' has no namespace.');
        }

        $endpoint = QueueEndpoint::forNamespace($namespace);

        if ($endpoint === '') {
            // A worker with no endpoint would boot, fail every claim, and look
            // like a broken queue. Refusing to start is the honest failure.
            throw new RuntimeException('dply Queue has no public URL configured; cannot start workers.');
        }

        $credential = $this->credential($namespace);

        return [
            'QUEUE_CONNECTION' => 'dply',
            'DPLY_QUEUE_URL' => $endpoint,
            'DPLY_QUEUE_KEY' => $credential['access_key_id'],
            'DPLY_QUEUE_SECRET' => $credential['secret'],
        ];
    }

    /**
     * Reuse the namespace's live credential, minting one only if there is
     * none.
     *
     * A credential per worker would multiply rows by the scale factor and
     * make revocation a fleet-wide sweep instead of one row — and the
     * namespace epoch already provides the "revoke everything" lever.
     *
     * @return array{access_key_id: string, secret: string}
     */
    private function credential(QueueNamespace $namespace): array
    {
        $live = $namespace->liveCredentials()->first();

        if ($live instanceof QueueCredential && (string) $live->secret !== '') {
            return [
                'access_key_id' => $live->accessKeyId(),
                'secret' => (string) $live->secret,
            ];
        }

        $minted = QueueCredential::mint($namespace, __('Managed worker credential'));

        return [
            'access_key_id' => $minted['credential']->accessKeyId(),
            'secret' => $minted['plaintext'],
        ];
    }
}
