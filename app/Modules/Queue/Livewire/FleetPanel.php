<?php

declare(strict_types=1);

namespace App\Modules\Queue\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\ManagedQueueWorker;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\QueueJobDurations;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Managed worker fleets for one queue namespace.
 *
 * The whole configuration surface is two numbers and a class: memory per
 * worker and the [min, max] range. Everything else — when to add a worker,
 * where it lands, how long its lease runs — is dply's problem, and exposing
 * any of it would be exposing an implementation detail as a setting someone
 * then has to tune.
 *
 * Its own component rather than another section of the namespace page: the
 * live readout polls, and polling the whole page would re-run the failed-job
 * reader and the depth query every five seconds too.
 */
class FleetPanel extends Component
{
    use DispatchesToastNotifications;

    /** Cloud's limit, and a sane one: the name is part of a URL path. */
    private const MAX_QUEUE_NAME = 39;

    public string $namespaceId = '';

    public bool $creating = false;

    public string $queue = 'default';

    public string $class = ManagedQueueFleet::CLASS_FLEX;

    public int $memory_mib = 256;

    public int $min_workers = 0;

    public int $max_workers = 3;

    /** Fleet currently being resized, if any. */
    public ?string $editingId = null;

    public function mount(QueueNamespace $queueNamespace): void
    {
        $this->authorize('view', $queueNamespace);
        $this->namespaceId = $queueNamespace->id;
    }

    private function namespace(): QueueNamespace
    {
        return QueueNamespace::query()->findOrFail($this->namespaceId);
    }

    /**
     * Fleets flattened to primitives, with what each is actually doing.
     *
     * The live worker count comes from the worker rows rather than
     * `desired_workers`: desired is what the autoscaler asked for, and the
     * gap between the two is exactly what an operator needs to see when a
     * substrate is refusing to place containers.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function fleets(): array
    {
        $namespace = $this->namespace();
        $store = app(QueueStore::class);
        $durations = app(QueueJobDurations::class);

        return ManagedQueueFleet::query()
            ->where('namespace_id', $namespace->id)
            ->orderBy('queue')
            ->get()
            ->map(function (ManagedQueueFleet $fleet) use ($namespace, $store, $durations): array {
                // The store is on another connection; it being unreachable
                // must degrade this row to "unknown", not 500 the page.
                try {
                    $depth = $store->depth($namespace, $fleet->queue)->toArray();
                } catch (Throwable) {
                    $depth = null;
                }

                return [
                    'id' => $fleet->id,
                    'queue' => $fleet->queue,
                    'class' => $fleet->class,
                    'status' => $fleet->status,
                    'memory_mib' => $fleet->memory_mib,
                    'min_workers' => $fleet->min_workers,
                    'max_workers' => $fleet->max_workers,
                    'desired' => $fleet->desired_workers,
                    'running' => ManagedQueueWorker::query()->where('fleet_id', $fleet->id)->live()->count(),
                    'depth' => $depth,
                    'avg_job_seconds' => $durations->average($namespace, $fleet->queue)
                        ?? ($fleet->meta['avg_job_seconds'] ?? null),
                    'reason' => $fleet->meta['last_reason'] ?? null,
                    'last_scaled_at' => $fleet->last_scaled_at?->diffForHumans(),
                    'image' => trim((string) ($fleet->meta['image'] ?? '')),
                ];
            })
            ->all();
    }

    public function startCreating(): void
    {
        $this->authorize('update', $this->namespace());
        $this->creating = true;
    }

    public function cancelCreating(): void
    {
        $this->creating = false;
        $this->resetValidation();
    }

    public function create(): void
    {
        $namespace = $this->namespace();
        $this->authorize('update', $namespace);

        $this->validate($this->rules());

        // One fleet per queue name: two autoscalers on one signal would fight,
        // and the unique index would reject the second anyway — better to say
        // so than to surface a constraint violation.
        $exists = ManagedQueueFleet::query()
            ->where('namespace_id', $namespace->id)
            ->where('queue', $this->queue)
            ->exists();

        if ($exists) {
            $this->addError('queue', __('This namespace already has a fleet draining :queue.', ['queue' => $this->queue]));

            return;
        }

        ManagedQueueFleet::query()->create([
            'namespace_id' => $namespace->id,
            'organization_id' => $namespace->organization_id,
            'queue' => $this->queue,
            'class' => $this->class,
            'status' => ManagedQueueFleet::STATUS_ACTIVE,
            'memory_mib' => $this->memory_mib,
            // A pro fleet is defined by never sleeping, so its floor is at
            // least one whatever was typed.
            'min_workers' => $this->class === ManagedQueueFleet::CLASS_PRO
                ? max(1, $this->min_workers)
                : $this->min_workers,
            'max_workers' => $this->max_workers,
        ]);

        $this->creating = false;
        unset($this->fleets);

        $this->toastSuccess(__('Fleet created. Workers start when jobs arrive on :queue.', ['queue' => $this->queue]));
    }

    public function edit(string $fleetId): void
    {
        $fleet = $this->fleet($fleetId);
        $this->authorize('update', $this->namespace());

        $this->editingId = $fleet->id;
        $this->class = $fleet->class;
        $this->memory_mib = $fleet->memory_mib;
        $this->min_workers = $fleet->min_workers;
        $this->max_workers = $fleet->max_workers;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->resetValidation();
    }

    /**
     * Resize a fleet.
     *
     * Takes effect on the next tick for the range, and on worker replacement
     * for memory: a running worker keeps the size it was started at, and is
     * billed at that size until it is replaced.
     */
    public function save(): void
    {
        $fleet = $this->fleet((string) $this->editingId);
        $this->authorize('update', $this->namespace());

        $this->validate([
            'memory_mib' => $this->memoryRule(),
            'min_workers' => 'required|integer|min:0|max:1000',
            'max_workers' => 'required|integer|min:1|max:1000|gte:min_workers',
        ]);

        $fleet->forceFill([
            'memory_mib' => $this->memory_mib,
            'min_workers' => $fleet->class === ManagedQueueFleet::CLASS_PRO
                ? max(1, $this->min_workers)
                : $this->min_workers,
            'max_workers' => $this->max_workers,
        ])->save();

        $this->editingId = null;
        unset($this->fleets);

        $this->toastSuccess(__('Fleet resized. The range applies on the next tick; memory applies as workers are replaced.'));
    }

    /**
     * Stop processing without losing anything.
     *
     * Pushes keep landing while paused — the backlog is held, not dropped —
     * and the workers wind down to zero, so nothing is billed while it waits.
     */
    public function togglePause(string $fleetId): void
    {
        $fleet = $this->fleet($fleetId);
        $this->authorize('update', $this->namespace());

        $paused = $fleet->status === ManagedQueueFleet::STATUS_PAUSED;

        $fleet->forceFill([
            'status' => $paused ? ManagedQueueFleet::STATUS_ACTIVE : ManagedQueueFleet::STATUS_PAUSED,
        ])->save();

        unset($this->fleets);

        $this->toastSuccess($paused
            ? __('Fleet resumed — dply is sizing it against the backlog again.')
            : __('Fleet paused. Jobs keep arriving and are held until you resume; workers wind down to zero.'));
    }

    /**
     * Delete a fleet.
     *
     * Worker rows are left behind on purpose: they are the billing record for
     * time already spent, and deleting a fleet must not delete an invoice.
     */
    public function delete(string $fleetId): void
    {
        $fleet = $this->fleet($fleetId);
        $this->authorize('update', $this->namespace());

        $fleet->forceFill(['status' => ManagedQueueFleet::STATUS_PAUSED])->save();
        $fleet->delete();

        unset($this->fleets);

        $this->toastSuccess(__('Fleet deleted. Its workers stop on the next tick; usage already recorded is kept.'));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'queue' => ['required', 'string', 'max:'.self::MAX_QUEUE_NAME, 'regex:/^[A-Za-z0-9_-]+$/'],
            'class' => 'required|in:'.ManagedQueueFleet::CLASS_FLEX.','.ManagedQueueFleet::CLASS_PRO,
            'memory_mib' => $this->memoryRule(),
            'min_workers' => 'required|integer|min:0|max:1000',
            'max_workers' => 'required|integer|min:1|max:1000|gte:min_workers',
        ];
    }

    /**
     * Flex tops out at 2 GiB, Pro at 8 GiB — the classes differ in ceiling as
     * well as in whether they sleep.
     */
    private function memoryRule(): string
    {
        $max = $this->class === ManagedQueueFleet::CLASS_PRO ? 8192 : 2048;

        return 'required|integer|min:256|max:'.$max;
    }

    private function fleet(string $fleetId): ManagedQueueFleet
    {
        return ManagedQueueFleet::query()
            ->where('namespace_id', $this->namespaceId)
            ->findOrFail($fleetId);
    }

    public function render(): View
    {
        return view('livewire.queues.fleet-panel', [
            'canManage' => auth()->user()?->can('update', $this->namespace()) ?? false,
            'runtimeConfigured' => (string) config('queue_service.fleets.runtime', 'fake') !== 'fake',
        ]);
    }
}
