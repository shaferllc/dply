<?php

declare(strict_types=1);

namespace App\Modules\Queue\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Modules\Queue\Actions\CreateQueueNamespace;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Support\QueueEndpoint;
use App\Modules\Queue\Support\QueueEntitlements;
use App\Modules\Queue\Support\QueueTier;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * The dply Queue index — the org's managed job queues, what they cost, and how
 * deep they are right now.
 *
 * Session-scoped like every other product index (Edge, Cloud, Serverless); the
 * route group already gates on `surface.queue`, so the off-state is a 404 and
 * this component only runs when Queue is live for the org.
 */
class Queues extends Component
{
    use DispatchesToastNotifications;

    public Organization $organization;

    /** Create-namespace modal. */
    public string $createName = '';

    public string $createTier = '';

    /** Re-consent required when the namespace being created will be billed. */
    public bool $confirmCreateCharge = false;

    /** The namespace open in the delete-confirmation modal, if any. */
    public ?string $deletingId = null;

    public function mount(): void
    {
        $organization = auth()->user()?->currentOrganization();
        abort_if($organization === null, 403);

        $this->organization = $organization;
        $this->authorize('viewAny', QueueNamespace::class);
        $this->createTier = (string) config('queue_service.default_tier', 'standard');
    }

    public function startCreate(): void
    {
        $this->authorize('create', QueueNamespace::class);

        $this->createName = '';
        $this->createTier = (string) config('queue_service.default_tier', 'standard');
        $this->confirmCreateCharge = false;
        $this->dispatch('open-modal', 'queue-create-modal');
    }

    public function cancelCreate(): void
    {
        $this->reset(['createName', 'confirmCreateCharge']);
        $this->dispatch('close-modal', 'queue-create-modal');
    }

    public function createNamespace(CreateQueueNamespace $action): void
    {
        $this->authorize('create', QueueNamespace::class);

        $name = trim($this->createName);

        if ($name === '') {
            $this->toastError(__('Give the queue a name.'));

            return;
        }

        // A namespace created from this page has no site attached, so it always
        // bills — the free path is Serverless-attached namespaces, which are
        // created by the deploy, not here. Consent is unconditional rather than
        // price-conditional for exactly that reason.
        if (! $this->confirmCreateCharge) {
            $this->toastError(__('Confirm the monthly charge to create this queue.'));

            return;
        }

        try {
            $action->handle($this->organization, $name, null, auth()->id());
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Queue created. Copy its credentials to point an app at it.'));
        $this->cancelCreate();
    }

    public function confirmDelete(string $id): void
    {
        $this->deletingId = $id;
        $this->dispatch('open-modal', 'queue-delete-modal');
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
        $this->dispatch('close-modal', 'queue-delete-modal');
    }

    public function deleteNamespace(): void
    {
        $namespace = QueueNamespace::query()->find($this->deletingId);

        if (! $namespace instanceof QueueNamespace || $namespace->organization_id !== $this->organization->id) {
            $this->toastError(__('That queue no longer exists.'));
            $this->cancelDelete();

            return;
        }

        $this->authorize('delete', $namespace);

        $namespace->delete();

        $this->toastSuccess(__('Queue deleted. It no longer counts toward your bill.'));
        $this->cancelDelete();
    }

    public function render(): View
    {
        $namespaces = $this->organization->queueNamespaces()
            ->with('site:id,name,serverless_backend')
            ->orderBy('created_at')
            ->get();

        $store = app(QueueStore::class);

        // Depth is read per namespace off the dply_queue connection. Cheap
        // enough at the counts a plan allows (max_namespaces is single digits),
        // and a failure here must degrade to "unknown" rather than take the
        // page down — the store is a separate database and can be unreachable
        // while the control plane is fine.
        $depths = [];
        foreach ($namespaces as $namespace) {
            try {
                $depths[$namespace->id] = $store->depth($namespace);
            } catch (Throwable) {
                $depths[$namespace->id] = null;
            }
        }

        $entitlement = app(QueueEntitlements::class)->for($this->organization);
        $billableCount = $namespaces->filter(fn (QueueNamespace $n): bool => $n->isBillable())->count();

        return view('livewire.queues.index', [
            'namespaces' => $namespaces,
            'depths' => $depths,
            'tiers' => QueueTier::all(),
            'entitlement' => $entitlement,
            'billableCount' => $billableCount,
            'freeCount' => $namespaces->count() - $billableCount,
            'monthlyCents' => $namespaces->sum(fn (QueueNamespace $n): int => $n->priceCents()),
            'billingEnabled' => (bool) config('queue_service.billing.enabled', false),
            'endpointBase' => QueueEndpoint::base(),
            'atLimit' => $entitlement->hasNamespaceLimit() && $namespaces->count() >= $entitlement->maxNamespaces,
            'canManage' => auth()->user()?->can('create', QueueNamespace::class) ?? false,
            'deletingNamespace' => $this->deletingId !== null
                ? $namespaces->firstWhere('id', $this->deletingId)
                : null,
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Queues'), 'icon' => 'queue-list'],
            ],
        ]);
    }
}
