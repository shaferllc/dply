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
        $entitlement = $this->entitlements->for($organization);

        if (! $entitlement->available) {
            throw new RuntimeException('dply Queue is not available on this plan.');
        }

        $existing = QueueNamespace::query()
            ->where('organization_id', $organization->id)
            ->count();

        if ($entitlement->maxNamespaces > 0 && $existing >= $entitlement->maxNamespaces) {
            throw new RuntimeException(
                'This plan allows '.$entitlement->maxNamespaces.' queue namespace(s). Delete one or upgrade to add another.'
            );
        }

        $namespace = QueueNamespace::query()->create([
            'organization_id' => $organization->id,
            'site_id' => $site?->id,
            'name' => trim($name) !== '' ? trim($name) : 'default',
            'status' => QueueNamespace::STATUS_ACTIVE,
            'tier' => $entitlement->tier,
            'max_queue_depth' => $entitlement->maxQueueDepth > 0 ? $entitlement->maxQueueDepth : null,
        ]);

        $minted = QueueCredential::mint($namespace, __('Default credential'), userId: $userId);

        return [
            'namespace' => $namespace,
            'credential' => $minted['credential'],
            'plaintext' => $minted['plaintext'],
        ];
    }
}
