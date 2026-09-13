<?php

declare(strict_types=1);

namespace App\Modules\Queue\Actions;

use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\QueueNamespace;

/**
 * Create a worker fleet for one queue of a namespace.
 *
 * Shared by the fleet panel and the site Queue page's "Run jobs on" picker, so
 * the rules below have one home rather than two copies that drift apart.
 */
final class CreateManagedQueueFleet
{
    /**
     * @param  array{queue: string, class: string, memory_mib: int, min_workers: int, max_workers: int, image?: string, registry_username?: ?string, registry_password?: ?string}  $attributes
     * @return ManagedQueueFleet|null null when the queue already has a fleet
     */
    public function handle(QueueNamespace $namespace, array $attributes): ?ManagedQueueFleet
    {
        // One fleet per queue name: two autoscalers on one signal would fight,
        // and the unique index would reject the second anyway.
        $exists = ManagedQueueFleet::query()
            ->where('namespace_id', $namespace->id)
            ->where('queue', $attributes['queue'])
            ->exists();

        if ($exists) {
            return null;
        }

        return ManagedQueueFleet::query()->create([
            'namespace_id' => $namespace->id,
            'organization_id' => $namespace->organization_id,
            'queue' => $attributes['queue'],
            'class' => $attributes['class'],
            'status' => ManagedQueueFleet::STATUS_ACTIVE,
            'image' => trim((string) ($attributes['image'] ?? '')),
            'registry_username' => $attributes['registry_username'] ?? null,
            'registry_password' => $attributes['registry_password'] ?? null,
            'memory_mib' => $attributes['memory_mib'],
            // A pro fleet is defined by never sleeping, so its floor is at
            // least one whatever was typed.
            'min_workers' => $attributes['class'] === ManagedQueueFleet::CLASS_PRO
                ? max(1, $attributes['min_workers'])
                : $attributes['min_workers'],
            'max_workers' => $attributes['max_workers'],
        ]);
    }
}
