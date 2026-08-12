<?php

declare(strict_types=1);

namespace App\Modules\Queue\Actions;

use App\Models\Organization;
use App\Models\Site;
use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Support\QueueEntitlements;
use RuntimeException;

/**
 * Create a queue namespace and mint its first credential.
 *
 * A namespace is usable the moment it exists — there is nothing to provision
 * asynchronously, unlike a Redis cluster or a Functions namespace. That is
 * most of the product's value: the fix for a broken queue backend is
 * immediate rather than a five-minute wait on a managed database.
 */
final class CreateQueueNamespace
{
    public function __construct(private readonly QueueEntitlements $entitlements) {}

    /**
     * @return array{namespace: QueueNamespace, credential: QueueCredential, plaintext: string}
     */
    public function handle(
        Organization $organization,
        string $name,
        ?Site $site = null,
        ?string $userId = null,
    ): array {
        // The platform kill switch, checked here rather than at the call sites
        // so it actually holds. `ServerlessQueueProvisioner` honoured it and the
        // control plane did not, which meant flipping DPLY_QUEUE_ENABLED off
        // stopped deploys wiring queues but still let the dashboard mint them —
        // exactly the namespaces there would be no data plane to serve.
        if (! (bool) config('queue_service.enabled', false)) {
            throw new RuntimeException('dply Queue is currently unavailable.');
        }

        $entitlement = $this->entitlements->for($organization);

        if (! $entitlement->available) {
            throw new RuntimeException('dply Queue is not available on this plan.');
        }

        $existing = QueueNamespace::query()
            ->where('organization_id', $organization->id)
            ->count();

        if ($entitlement->hasNamespaceLimit() && $existing >= $entitlement->maxNamespaces) {
            throw new RuntimeException(
                'This plan allows '.$entitlement->maxNamespaces.' queue namespace(s). Delete one or upgrade to add another.'
            );
        }

        // Depth is stamped onto the row from the tier rather than read live at
        // push time, so a tier's capacity is fixed at the moment the customer
        // bought it: re-pricing a tier later must not silently shrink a running
        // queue underneath someone.
        $tier = $entitlement->defaultTier();

        $namespace = QueueNamespace::query()->create([
            'organization_id' => $organization->id,
            'site_id' => $site?->id,
            'name' => trim($name) !== '' ? trim($name) : 'default',
            'status' => QueueNamespace::STATUS_ACTIVE,
            'tier' => $tier->slug,
            'max_queue_depth' => $tier->hasQueueDepthLimit() ? $tier->maxQueueDepth : null,
        ]);

        $minted = QueueCredential::mint($namespace, __('Default credential'), userId: $userId);

        return [
            'namespace' => $namespace,
            'credential' => $minted['credential'],
            'plaintext' => $minted['plaintext'],
        ];
    }
}
