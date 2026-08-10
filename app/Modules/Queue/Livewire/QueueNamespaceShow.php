<?php

declare(strict_types=1);

namespace App\Modules\Queue\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Modules\Queue\Actions\RotateQueueCredential;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\QueueFailedJobReader;
use App\Modules\Queue\Support\QueueEndpoint;
use App\Modules\Queue\Support\QueueTier;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Per-namespace detail — credentials, wiring, live depth, throughput, and
 * failed jobs.
 *
 * The failed-jobs panel is the reason this page is not just a status readout.
 * Horizon is hard-wired to RedisQueue, so a Cloud or BYO customer adopting dply
 * Queue loses their dashboard — and under the pricing model they are exactly
 * who pays for this. See docs/adr/managed-services-tier.md, decision 9.
 */
class QueueNamespaceShow extends Component
{
    use DispatchesToastNotifications;

    public Organization $organization;

    public QueueNamespace $namespace;

    public string $selectedTier = '';

    public bool $confirmTierCharge = false;

    public bool $confirmingDelete = false;

    /** Plaintext secret, shown once immediately after a rotation. */
    public ?string $freshSecret = null;

    #[Url]
    public string $tab = 'overview';

    /** Failed job open in the detail modal, if any. */
    public ?string $inspectingJobId = null;

    public function mount(QueueNamespace $queueNamespace): void
    {
        $organization = auth()->user()?->currentOrganization();
        abort_if($organization === null, 403);

        // The session supplies the org, so this ownership check is the only
        // thing standing between a guessed ULID and another org's queue. 404
        // rather than 403: whether the id exists is not ours to confirm.
        abort_unless($queueNamespace->organization_id === $organization->id, 404);

        $this->authorize('view', $queueNamespace);

        $this->organization = $organization;
        $this->namespace = $queueNamespace;
        $this->selectedTier = $queueNamespace->tierConfig()->slug;
    }

    public function startTierChange(): void
    {
        $this->selectedTier = $this->namespace->tierConfig()->slug;
        $this->confirmTierCharge = false;
        $this->dispatch('open-modal', 'queue-tier-modal');
    }

    public function cancelTierChange(): void
    {
        $this->reset(['confirmTierCharge']);
        $this->selectedTier = $this->namespace->tierConfig()->slug;
        $this->dispatch('close-modal', 'queue-tier-modal');
    }

    public function changeTier(): void
    {
        $this->authorize('update', $this->namespace);

        $tiers = QueueTier::all();

        if (! array_key_exists($this->selectedTier, $tiers)) {
            $this->toastError(__('Pick a capacity tier.'));

            return;
        }

        $current = $this->namespace->tierConfig();
        $target = $tiers[$this->selectedTier];

        if ($target->slug === $current->slug) {
            $this->toastWarning(__('This queue is already on the :tier tier.', ['tier' => $current->label]));

            return;
        }

        // Only ask for consent when the bill actually goes up — and only when
        // the namespace is billable at all. A Serverless-attached queue moving
        // tiers changes its capacity and nothing else.
        $raisesBill = $this->namespace->isBillable() && $target->priceCents > $current->priceCents;

        if ($raisesBill && ! $this->confirmTierCharge) {
            $this->toastError(__('Confirm the new monthly charge to upgrade.'));

            return;
        }

        // Depth is stamped on the row, not read from config at push time, so a
        // tier move has to rewrite it or the new capacity never takes effect.
        $this->namespace->forceFill([
            'tier' => $target->slug,
            'max_queue_depth' => $target->hasQueueDepthLimit() ? $target->maxQueueDepth : null,
        ])->save();

        $this->namespace->refresh();

        $this->toastSuccess(__('Queue moved to the :tier tier.', ['tier' => $target->label]));
        $this->cancelTierChange();
    }

    public function rotateCredential(RotateQueueCredential $action): void
    {
        $this->authorize('manageCredentials', $this->namespace);

        try {
            $rotated = $action->handle($this->namespace, __('Rotated credential'), auth()->id());
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->freshSecret = $rotated['plaintext'];
        $this->namespace->refresh();

        $this->toastSuccess(__('New credential minted. Copy the secret now — it is shown once.'));
    }

    public function confirmDelete(): void
    {
        $this->confirmingDelete = true;
        $this->dispatch('open-modal', 'queue-delete-modal');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
        $this->dispatch('close-modal', 'queue-delete-modal');
    }

    public function deleteNamespace(): void
    {
        $this->authorize('delete', $this->namespace);

        $this->namespace->delete();

        $this->toastSuccess(__('Queue deleted. It no longer counts toward your bill.'));

        // Livewire's redirect() returns void and skips the render — nothing to
        // return, and returning it would hand back null.
        $this->redirect(route('queues.index'), navigate: true);
    }

    public function inspectJob(string $id): void
    {
        $this->inspectingJobId = $id;
        $this->dispatch('open-modal', 'queue-failed-job-modal');
    }

    public function closeJob(): void
    {
        $this->inspectingJobId = null;
        $this->dispatch('close-modal', 'queue-failed-job-modal');
    }

    public function retryJob(string $id, QueueFailedJobReader $reader): void
    {
        $this->authorize('update', $this->namespace);

        if (! $reader->retry($this->namespace, $id)) {
            $this->toastError(__('Could not retry that job — it may already have been retried or deleted.'));

            return;
        }

        $this->closeJob();
        $this->toastSuccess(__('Job pushed back onto the queue.'));
    }

    public function forgetJob(string $id, QueueFailedJobReader $reader): void
    {
        $this->authorize('update', $this->namespace);

        $reader->forget($this->namespace, $id);

        $this->closeJob();
        $this->toastSuccess(__('Failed job deleted.'));
    }

    public function render(QueueFailedJobReader $reader): View
    {
        $store = app(QueueStore::class);

        // The store lives on a separate connection; it being unreachable must
        // degrade this page to "unknown", not 500 it.
        try {
            $depth = $store->depth($this->namespace);
        } catch (Throwable) {
            $depth = null;
        }

        try {
            $failedJobs = $reader->recent($this->namespace);
            $failedJobsAvailable = true;
        } catch (Throwable) {
            $failedJobs = [];
            $failedJobsAvailable = false;
        }

        $inspecting = null;
        foreach ($failedJobs as $job) {
            if ($job['id'] === $this->inspectingJobId) {
                $inspecting = $job;
                break;
            }
        }

        return view('livewire.queues.show', [
            'depth' => $depth,
            'tier' => $this->namespace->tierConfig(),
            'tiers' => QueueTier::all(),
            'endpoint' => QueueEndpoint::forNamespace($this->namespace),
            'credentials' => $this->namespace->credentials()->orderByDesc('created_at')->get(),
            'liveCredential' => $this->namespace->liveCredentials()->first(),
            'billable' => $this->namespace->isBillable(),
            'billingEnabled' => (bool) config('queue_service.billing.enabled', false),
            'throughput' => $reader->dailyThroughput($this->namespace),
            'failedJobs' => $failedJobs,
            'failedJobsAvailable' => $failedJobsAvailable,
            // Whether dply is the app's failed-job store at all. For apps dply
            // wires this is true; an external app keeps failures in its own DB
            // until it opts in, and the panel has to say so rather than imply
            // zero failures.
            'ownsFailedJobs' => $this->namespace->site_id !== null,
            'inspectingJob' => $inspecting,
            'canManage' => auth()->user()?->can('update', $this->namespace) ?? false,
            'canManageCredentials' => auth()->user()?->can('manageCredentials', $this->namespace) ?? false,
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Queues'), 'href' => route('queues.index'), 'icon' => 'queue-list'],
                ['label' => $this->namespace->name, 'icon' => 'queue-list'],
            ],
        ]);
    }
}
