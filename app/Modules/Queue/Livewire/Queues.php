<?php

declare(strict_types=1);

namespace App\Modules\Queue\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Modules\Queue\Actions\CreateQueueNamespace;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\QueueFailedJobReader;
use App\Modules\Queue\Support\QueueDepth;
use App\Modules\Queue\Support\QueueEndpoint;
use App\Modules\Queue\Support\QueueEntitlements;
use App\Modules\Queue\Support\QueueTier;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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

    /** Trailing window for the activity chart, in days. */
    private const ACTIVITY_DAYS = 14;

    public Organization $organization;

    /** Create-namespace modal. */
    public string $createName = '';

    public string $createTier = '';

    /** Re-consent required when the namespace being created will be billed. */
    public bool $confirmCreateCharge = false;

    /** The namespace open in the delete-confirmation modal, if any. */
    public ?string $deletingId = null;

    /** Must match the namespace's name before a delete is allowed through. */
    public string $deleteConfirmation = '';

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

        // Consent is asked for only when there is actually a charge to consent
        // to. A namespace created from this page has no site attached, so it
        // WILL bill — but not while `billing.enabled` is off, and demanding a
        // checkbox for a $0.00 beta queue trains people to click past the one
        // that matters. Same rule the tier change uses on the detail page, so
        // the two flows cannot disagree about when money is at stake.
        if ($this->billingEnabled() && ! $this->confirmCreateCharge) {
            $this->toastError(__('Confirm the monthly charge to create this queue.'));

            return;
        }

        try {
            $created = $action->handle($this->organization, $name, null, auth()->id());
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->cancelCreate();

        // The secret is shown exactly once, and it is useless without the
        // endpoint — so hand the operator straight to the page that has both.
        // It travels in the session rather than the URL: a query string would
        // put the secret in browser history and every proxy log on the way.
        session()->flash('queue.revealed_secret', $created['plaintext']);

        $this->redirect(route('queues.show', $created['namespace']), navigate: true);
    }

    /**
     * Stop or resume a queue without deleting it.
     *
     * A paused namespace refuses pushes but keeps serving receives, so the
     * inflow can be cut while the jobs already queued still drain — see
     * {@see QueueNamespace::acceptsPushes()}. That is the difference between
     * pausing and deleting, and it is why this is worth a control of its own.
     */
    public function togglePause(string $id): void
    {
        $namespace = $this->ownedNamespace($id);

        $this->authorize('update', $namespace);

        $paused = $namespace->status === QueueNamespace::STATUS_PAUSED;

        $namespace->forceFill([
            'status' => $paused ? QueueNamespace::STATUS_ACTIVE : QueueNamespace::STATUS_PAUSED,
        ])->save();

        $this->toastSuccess($paused
            ? __('Queue resumed — it accepts pushes again.')
            : __('Queue paused. New pushes are refused; anything already queued still drains.'));
    }

    public function confirmDelete(string $id): void
    {
        $this->deletingId = $id;
        $this->deleteConfirmation = '';
        $this->dispatch('open-modal', 'queue-delete-modal');
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
        $this->deleteConfirmation = '';
        $this->dispatch('close-modal', 'queue-delete-modal');
    }

    /**
     * Delete a queue, discarding whatever is still in it.
     *
     * Gated on typing the name. Every other destructive control in dply is a
     * confirm dialog, but this one silently throws away jobs the customer's
     * app believes are still going to run — there is no undo and no trace, so
     * the friction is deliberate.
     */
    public function deleteNamespace(): void
    {
        $namespace = QueueNamespace::query()->find($this->deletingId);

        if (! $namespace instanceof QueueNamespace || $namespace->organization_id !== $this->organization->id) {
            $this->toastError(__('That queue no longer exists.'));
            $this->cancelDelete();

            return;
        }

        $this->authorize('delete', $namespace);

        if (trim($this->deleteConfirmation) !== $namespace->name) {
            $this->toastError(__('Type :name to confirm.', ['name' => $namespace->name]));

            return;
        }

        $namespace->delete();

        $this->toastSuccess(__('Queue deleted. It no longer counts toward your bill.'));
        $this->cancelDelete();
    }

    /**
     * A namespace in this organization, or a 404.
     *
     * Scoped at the lookup rather than checked after it: the query cannot
     * return another org's row, so there is no path where an authorize() call
     * is reached holding the wrong namespace.
     */
    private function ownedNamespace(string $id): QueueNamespace
    {
        return $this->organization->queueNamespaces()->findOrFail($id);
    }

    private function billingEnabled(): bool
    {
        return (bool) config('queue_service.billing.enabled', false);
    }

    /**
     * The numbers the queue console reads out.
     *
     * The verdict this product turns on is the depth cliff: a tier has a
     * `maxQueueDepth` past which pushes are REJECTED, so how close the fullest
     * namespace is to its own ceiling matters more than the spend or the count.
     * That is measured per namespace, not workspace-wide — one queue at 99%
     * hidden behind three idle ones is exactly the outage this is meant to
     * catch.
     *
     * Depth and failure counts come from a separate database and may be null
     * (unreachable). Null is carried through as "unknown" rather than folded in
     * as zero, which would read as a healthy empty queue.
     *
     * @param  Collection<int, QueueNamespace>  $namespaces
     * @param  array<string, QueueDepth|null>  $depths
     * @param  array<string, int|null>  $failed
     * @param  array<string, list<array{date: string, jobs: int}>>  $throughput
     * @return array<string, mixed>
     */
    private function queueMetrics(
        Collection $namespaces,
        array $depths,
        array $failed,
        array $throughput,
    ): array {
        $known = $namespaces->filter(fn (QueueNamespace $n): bool => ($depths[$n->id] ?? null) instanceof QueueDepth);

        $pending = $known->sum(fn (QueueNamespace $n): int => $depths[$n->id]->pending);
        $delayed = $known->sum(fn (QueueNamespace $n): int => $depths[$n->id]->delayed);
        $reserved = $known->sum(fn (QueueNamespace $n): int => $depths[$n->id]->reserved);

        // The namespace closest to its own push-rejection threshold. Tiers with
        // an unlimited depth (0) are excluded: they have no cliff to be near.
        $fullest = null;
        $fullestPercent = 0;
        foreach ($known as $namespace) {
            $max = $namespace->tierConfig()->maxQueueDepth;
            if ($max <= 0) {
                continue;
            }

            $percent = (int) round($depths[$namespace->id]->total() / $max * 100);
            if ($fullest === null || $percent > $fullestPercent) {
                $fullest = $namespace;
                $fullestPercent = $percent;
            }
        }

        // Workspace throughput per day, summed across namespaces. Every series
        // is the same window with gaps filled, so they line up by index.
        $activity = [];
        foreach ($throughput as $series) {
            foreach ($series as $index => $day) {
                $activity[$index] ??= ['date' => $day['date'], 'jobs' => 0];
                $activity[$index]['jobs'] += $day['jobs'];
            }
        }
        ksort($activity);
        $activity = array_values($activity);

        $failedKnown = array_filter($failed, fn (?int $count): bool => $count !== null);

        return [
            'namespaces' => $namespaces->count(),
            'active' => $namespaces->filter(fn (QueueNamespace $n): bool => $n->isActive())->count(),
            'paused' => $namespaces->filter(fn (QueueNamespace $n): bool => $n->status === QueueNamespace::STATUS_PAUSED)->count(),

            // True when at least one namespace's depth could not be read, so the
            // console can say the totals are partial instead of implying they
            // are the whole picture.
            'depthPartial' => $known->count() !== $namespaces->count(),
            'pending' => $pending,
            'delayed' => $delayed,
            'reserved' => $reserved,
            'backlog' => $pending + $delayed + $reserved,

            'fullest' => $fullest,
            'fullestPercent' => min(100, $fullestPercent),
            'fullestCap' => $fullest?->tierConfig()->maxQueueDepth ?? 0,

            'failed' => array_sum($failedKnown),
            'failedPartial' => count($failedKnown) !== count($failed),

            'activity' => $activity,
            'jobsToday' => (int) ($activity[count($activity) - 1]['jobs'] ?? 0),
            'jobsWindow' => array_sum(array_column($activity, 'jobs')),
            'activityDays' => self::ACTIVITY_DAYS,
        ];
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

        // Outstanding failures and push throughput, read the same defensively:
        // both live in the job store, and neither is worth taking the page down
        // for. Absent means "unknown", which the console renders as such.
        $reader = app(QueueFailedJobReader::class);
        $failed = [];
        $throughput = [];
        foreach ($namespaces as $namespace) {
            try {
                $failed[$namespace->id] = $reader->outstandingCount($namespace);
            } catch (Throwable) {
                $failed[$namespace->id] = null;
            }

            try {
                $throughput[$namespace->id] = $reader->dailyThroughput($namespace, self::ACTIVITY_DAYS);
            } catch (Throwable) {
                $throughput[$namespace->id] = [];
            }
        }

        $entitlement = app(QueueEntitlements::class)->for($this->organization);
        $billableCount = $namespaces->filter(fn (QueueNamespace $n): bool => $n->isBillable())->count();

        return view('livewire.queues.index', [
            'namespaces' => $namespaces,
            'depths' => $depths,
            'failed' => $failed,
            'metrics' => $this->queueMetrics($namespaces, $depths, $failed, $throughput),
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
