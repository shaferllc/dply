<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\ConsoleAction;
use App\Models\SiteDeployment;

/**
 * One immutable snapshot of everything the deploy surfaces render — the latest
 * deployment, whether a deploy is live, the optimistic lock marker, the active
 * sync batch + persisted peer selection, and the in-flight / completed smart-fix
 * state.
 *
 * Built once per request by {@see SiteDeployCoordinator::status()} and shared by
 * BOTH the main Deploy page ({@see \App\Livewire\Sites\DeploymentsList}) and the
 * deploy sidebar ({@see \App\Livewire\Sites\DeployControl}), so the two surfaces
 * can never compute "is a deploy running" (etc.) differently. This is the single
 * read-side source of truth that the Q8 design settled on.
 */
final readonly class DeployStatus
{
    /**
     * @param  array{started_at?: string, deployment_id?: ?string}|null  $lock  optimistic deploy-active marker
     * @param  array{ids: list<string>, started_at?: string}|null  $syncBatch  the active sync batch, if any
     * @param  list<string>  $selectedPeerIds  persisted Sync peer selection (mirrored across surfaces)
     * @param  list<string>  $completedFixerKeys  fixer keys already run since the last deploy finished
     */
    public function __construct(
        public ?SiteDeployment $latest,
        public bool $inProgress,
        public ?array $lock,
        public ?array $syncBatch,
        public array $selectedPeerIds,
        public ?ConsoleAction $fixerInFlight,
        public array $completedFixerKeys,
    ) {}

    /** Whether a smart-fix is currently queued/running for the site. */
    public function fixerIsInFlight(): bool
    {
        return $this->fixerInFlight !== null && $this->fixerInFlight->isInFlight();
    }
}
