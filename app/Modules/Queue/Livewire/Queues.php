<?php

declare(strict_types=1);

namespace App\Modules\Queue\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Modules\Billing\Services\QueueUsageCostCalculator;
use App\Modules\Queue\Actions\CreateQueueNamespace;
use App\Modules\Queue\Actions\DeleteQueueNamespace;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\QueueUsageMeter;
use App\Modules\Queue\Services\ServerlessQueueProvisioner;
use App\Modules\Queue\Support\QueueEntitlements;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Org-level control plane for dply Queue — the managed job queue.
 *
 * Lists the organization's namespaces with the two numbers that matter
 * operationally (depth now, jobs pushed this month) and the endpoint an app
 * points at. Credential management lives on the per-namespace page: minting a
 * secret is the security-sensitive act here, and it deserves its own surface
 * rather than a row in a list.
 *
 * Sites dply deploys get a namespace provisioned automatically
 * ({@see ServerlessQueueProvisioner}); this page is
 * where an external app gets one, and where any of them are managed after.
 */
#[Layout('layouts.app')]
class Queues extends Component
{
    use DispatchesToastNotifications;

    public Organization $organization;

    /** Create-namespace modal. */
    public string $createName = '';

    /** The namespace open in the delete-confirmation modal, if any. */
    public ?string $deletingNamespaceId = null;

    /**
     * Typed by the operator to arm the delete. Deleting a namespace destroys
     * queued jobs, which no amount of undo brings back.
     */
    public string $deleteConfirmation = '';

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        $this->organization = $organization;
    }

    public function startCreate(): void
    {
        $this->authorize('create', QueueNamespace::class);

        if (! $this->available()) {
            $this->toastError(__('dply Queue isn’t enabled for this workspace.'));

            return;
        }

        $this->reset('createName');
        $this->dispatch('open-modal', 'queue-create-modal');
    }

    public function cancelCreate(): void
    {
        $this->reset('createName');
        $this->dispatch('close-modal', 'queue-create-modal');
    }

    public function createNamespace(CreateQueueNamespace $action): void
    {
        $this->authorize('create', QueueNamespace::class);

        if (! $this->available()) {
            $this->toastError(__('dply Queue isn’t enabled for this workspace.'));

            return;
        }

        $name = trim($this->createName);

        if ($name === '') {
            $this->toastError(__('Give the queue a name.'));

            return;
        }

        try {
            // The action enforces the plan's namespace ceiling and throws with
            // a message written for the operator, so it is shown as-is rather
            // than replaced with something vaguer.
            $created = $action->handle($this->organization, $name, userId: auth()->id());
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->cancelCreate();

        // Straight to the namespace page, carrying the plaintext in the session
        // rather than the URL — the secret is shown exactly once, and a query
        // string would put it in browser history and any proxy log on the way.
        session()->flash('queue.revealed_secret', $created['plaintext']);
        session()->flash('queue.revealed_credential_id', $created['credential']->id);

        $this->redirect(
            route('organizations.queues.show', [$this->organization, $created['namespace']]),
            navigate: true,
        );
    }

    public function confirmDelete(string $namespaceId): void
    {
        $this->deletingNamespaceId = $this->findNamespace($namespaceId)->id;
        $this->deleteConfirmation = '';
        $this->dispatch('open-modal', 'queue-delete-modal');
    }

    public function cancelDelete(): void
    {
        $this->reset(['deletingNamespaceId', 'deleteConfirmation']);
        $this->dispatch('close-modal', 'queue-delete-modal');
    }

    public function deleteNamespace(DeleteQueueNamespace $action): void
    {
        $namespace = $this->findNamespace((string) $this->deletingNamespaceId);
        $this->authorize('delete', $namespace);

        if (trim($this->deleteConfirmation) !== $namespace->name) {
            $this->toastError(__('Type the queue’s name to confirm.'));

            return;
        }

        $result = $action->handle($namespace);

        $this->cancelDelete();

        $this->toastSuccess(trans_choice(
            '{0}Queue deleted.|{1}Queue deleted, along with :count job still in it.|[2,*]Queue deleted, along with :count jobs still in it.',
            $result['jobs'],
            ['count' => number_format($result['jobs'])],
        ));
    }

    /**
     * Stop accepting pushes without touching what is already queued.
     *
     * Receives keep working while paused, so the way an operator empties a
     * paused queue is to let their workers drain it.
     */
    public function togglePause(string $namespaceId): void
    {
        $namespace = $this->findNamespace($namespaceId);
        $this->authorize('update', $namespace);

        $paused = $namespace->status === QueueNamespace::STATUS_PAUSED;

        $namespace->forceFill([
            'status' => $paused ? QueueNamespace::STATUS_ACTIVE : QueueNamespace::STATUS_PAUSED,
        ])->save();

        $this->toastSuccess($paused
            ? __('Queue resumed — it is accepting pushes again.')
            : __('Queue paused. New pushes are rejected; anything already queued still drains.'));
    }

    /** Resolve a namespace id, scoped to this organization (404 otherwise). */
    private function findNamespace(string $namespaceId): QueueNamespace
    {
        return QueueNamespace::query()
            ->where('organization_id', $this->organization->id)
            ->findOrFail($namespaceId);
    }

    /** The same two-key gate the provisioner applies: platform on, org flagged. */
    private function available(): bool
    {
        return (bool) config('queue_service.enabled', false)
            && Feature::for($this->organization)->active('surface.queue');
    }

    public function render(
        QueueStore $store,
        QueueUsageMeter $meter,
        QueueEntitlements $entitlements,
        QueueUsageCostCalculator $cost,
    ): View {
        $namespaces = QueueNamespace::query()
            ->where('organization_id', $this->organization->id)
            ->with('site:id,name,server_id')
            ->orderByDesc('created_at')
            ->get();

        // Depth is a live count per namespace on a separate connection. It
        // degrades to null rather than taking the page down — an unreadable
        // number is worth less than a page that renders.
        $depths = [];
        foreach ($namespaces as $namespace) {
            try {
                $depths[$namespace->id] = $store->depth($namespace)->toArray();
            } catch (Throwable $e) {
                $depths[$namespace->id] = null;
            }
        }

        $entitlement = $entitlements->for($this->organization);
        $usedJobs = $meter->monthToDateJobs($this->organization);

        return view('livewire.organizations.queues', [
            'namespaces' => $namespaces,
            'depths' => $depths,
            'entitlement' => $entitlement,
            'usedJobs' => $usedJobs,
            'overIncluded' => $cost->isOverIncluded($entitlement, $usedJobs),
            'estimate' => $cost->estimate($entitlement, $usedJobs),
            'billingLive' => $cost->isEnabled(),
            'featureActive' => $this->available(),
            'canManage' => auth()->user()?->can('create', QueueNamespace::class) ?? false,
            'deletingNamespace' => $this->deletingNamespaceId !== null
                ? $namespaces->firstWhere('id', $this->deletingNamespaceId)
                : null,
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $this->organization->name, 'href' => route('organizations.show', $this->organization), 'icon' => 'building-office-2'],
                ['label' => __('Queues'), 'icon' => 'queue-list'],
            ],
            'orgShellSection' => 'queues',
        ]);
    }
}
