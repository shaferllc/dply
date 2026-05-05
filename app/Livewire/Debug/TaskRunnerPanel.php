<?php

declare(strict_types=1);

namespace App\Livewire\Debug;

use App\Support\Debug\ActivityRow;
use App\Support\Debug\TaskRunnerActivityFeed;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Bottom-of-screen debug panel that lists every TaskRunner / SSH / Process run
 * across the workspace. Platform-admin only (re-checks the gate on mount and
 * also gated at the layout-include level via @can('viewPlatformAdmin')).
 *
 * Live tail is fed by the org-scoped Reverb channel via Echo (subscribed in
 * the inline Alpine controller in the blade view) — it dispatches the
 * 'debug-task-runner-activity' Livewire event which triggers a re-render of
 * the recent() / running() computed props for the Recent / All tabs.
 */
class TaskRunnerPanel extends Component
{
    public bool $authorized = false;

    public bool $expanded = false;

    /** 'live' | 'recent' | 'all' */
    public string $tab = 'live';

    public ?string $detailSource = null;

    public ?string $detailId = null;

    public ?string $detailOutput = null;

    public function mount(): void
    {
        $this->authorized = Gate::allows('viewPlatformAdmin');
    }

    #[Computed]
    public function organizationId(): ?string
    {
        return auth()->user()?->currentOrganization()?->id;
    }

    /**
     * @return \Illuminate\Support\Collection<int, ActivityRow>
     */
    #[Computed]
    public function recent(): \Illuminate\Support\Collection
    {
        if (! $this->authorized) {
            return collect();
        }

        return app(TaskRunnerActivityFeed::class)->recent(50, $this->organizationId);
    }

    /**
     * @return \Illuminate\Support\Collection<int, ActivityRow>
     */
    #[Computed]
    public function running(): \Illuminate\Support\Collection
    {
        if (! $this->authorized) {
            return collect();
        }

        return app(TaskRunnerActivityFeed::class)->running($this->organizationId);
    }

    public function toggle(): void
    {
        $this->expanded = ! $this->expanded;
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['live', 'recent', 'all'], true)) {
            return;
        }
        $this->tab = $tab;
    }

    public function viewDetail(string $source, string $id): void
    {
        if (! $this->authorized) {
            return;
        }

        $this->detailSource = $source;
        $this->detailId = $id;
        $this->detailOutput = app(TaskRunnerActivityFeed::class)->loadOutput($source, $id);
    }

    public function clearDetail(): void
    {
        $this->detailSource = null;
        $this->detailId = null;
        $this->detailOutput = null;
    }

    /**
     * Triggered by the inline Alpine Echo listener whenever a new
     * TaskRunnerActivityBroadcast frame arrives. The empty body is intentional —
     * the act of receiving the event re-runs render() which in turn re-evaluates
     * the recent() / running() #[Computed] props so the Recent / All tabs stay
     * fresh. The Live tab itself is fed client-side from the Alpine ring
     * buffer; this hook only matters when the operator switches tabs.
     */
    #[On('debug-task-runner-activity')]
    public function onActivity(): void
    {
        // no-op
    }

    public function render(): View
    {
        return view('livewire.debug.task-runner-panel');
    }
}
