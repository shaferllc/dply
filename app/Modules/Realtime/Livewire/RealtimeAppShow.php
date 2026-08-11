<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Models\SiteBinding;
use App\Modules\Realtime\Actions\DeleteRealtimeApp;
use App\Modules\Realtime\Actions\UpdateRealtimeApp;
use App\Modules\Realtime\Models\RealtimeApp;
use App\Modules\Realtime\Services\RealtimeBackendFactory;
use App\Modules\Realtime\Services\RealtimePublisher;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Per-app detail page for a managed realtime (broadcasting) app — the "instance"
 * an operator lands on from a site's broadcasting binding or the org Realtime
 * list. Shows the app's credentials, live connection stats, the sites that
 * broadcast through it, and the same tier-change / delete controls as the list.
 */
#[Layout('layouts.app')]
class RealtimeAppShow extends Component
{
    use DispatchesToastNotifications;

    public Organization $organization;

    public RealtimeApp $app;

    public string $selectedTier = '';

    /** Re-consent required when a tier change raises the monthly price. */
    public bool $confirmTierCharge = false;

    /** Whether the delete-confirmation modal is armed. */
    public bool $confirmingDelete = false;

    /** Current live connection count from the last stats read (transient). */
    public ?int $liveConnections = null;

    /**
     * Connection samples collected while this page has been open.
     *
     * There is no stats history table — the relay reports a current count and a
     * high-water mark, nothing more — so this is honestly scoped to the session
     * and labelled that way in the UI. Inventing a persisted history here would
     * mean drawing a chart from data nobody actually recorded.
     *
     * @var list<array{at: string, connections: int}>
     */
    public array $samples = [];

    /** Inline rename buffer; empty until the operator opens the editor. */
    public string $editName = '';

    public bool $editingName = false;

    /** Confirmation state for the two destructive-ish credential actions. */
    public bool $confirmingRotate = false;

    /** Playground: channel, event and payload the demo publishes on. */
    public string $demoChannel = 'demo-channel';

    public string $demoEvent = 'demo-event';

    public string $demoMessage = 'Hello from dply 👋';

    /**
     * The organization comes from the session, not the URL. The ownership check
     * below is what keeps `/realtime/{app}` from leaking another org's app to
     * anyone who guesses a ULID — it is now the only thing doing that job, so
     * it 404s rather than 403s: whether the id exists is not ours to confirm.
     */
    public function mount(RealtimeApp $realtimeApp): void
    {
        $organization = auth()->user()?->currentOrganization();
        abort_if($organization === null, 403);

        $this->authorize('view', $organization);
        abort_unless($realtimeApp->organization_id === $organization->id, 404);

        $this->organization = $organization;
        $this->app = $realtimeApp;
        $this->selectedTier = $realtimeApp->tierSlug();
    }

    public function startTierChange(): void
    {
        $this->selectedTier = $this->app->tierSlug();
        $this->confirmTierCharge = false;
        $this->dispatch('open-modal', 'realtime-tier-modal');
    }

    public function cancelTierChange(): void
    {
        $this->reset(['selectedTier', 'confirmTierCharge']);
        $this->selectedTier = $this->app->tierSlug();
        $this->dispatch('close-modal', 'realtime-tier-modal');
    }

    public function changeTier(UpdateRealtimeApp $action): void
    {
        $this->authorize('update', $this->organization);

        $tiers = (array) config('realtime.tiers', []);

        if (! array_key_exists($this->selectedTier, $tiers)) {
            $this->toastError(__('Pick a connection tier.'));

            return;
        }

        if ($this->selectedTier === $this->app->tierSlug()) {
            $this->toastWarning(__('That app is already on the :tier tier.', ['tier' => $this->app->tierConfig()['label']]));

            return;
        }

        // Require explicit re-consent only when the change raises the bill.
        $newPriceCents = (int) ($tiers[$this->selectedTier]['price_cents'] ?? 0);
        if ($newPriceCents > $this->app->priceCents() && ! $this->confirmTierCharge) {
            $this->toastError(__('Confirm the new monthly charge to upgrade.'));

            return;
        }

        $action->changeTier($this->app, $this->selectedTier);
        $this->app->refresh();

        $this->toastSuccess(__('Broadcasting app moved to the :tier tier. Your workspace bill updates to match.', [
            'tier' => (string) ($tiers[$this->selectedTier]['label'] ?? $this->selectedTier),
        ]));

        $this->cancelTierChange();
    }

    public function confirmDelete(): void
    {
        $this->confirmingDelete = true;
        $this->dispatch('open-modal', 'realtime-delete-modal');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
        $this->dispatch('close-modal', 'realtime-delete-modal');
    }

    public function deleteApp(DeleteRealtimeApp $action): void
    {
        $this->authorize('update', $this->organization);

        $action->handle($this->app);

        $this->toastSuccess(__('Broadcasting app deleted. It no longer counts toward your bill.'));

        // Livewire's redirect() returns void and skips the render.
        $this->redirect(route('realtime.index'), navigate: true);
    }

    /**
     * Pull the live stats (current + peak connections) from the relay and
     * persist the peak high-water mark, so the page reflects current usage on
     * demand. Surfaces a toast (used by the manual Refresh button).
     */
    public function refreshStats(): void
    {
        if (! $this->pullStats()) {
            $this->toastWarning(__('Could not read live stats from the relay right now.'));

            return;
        }

        $this->toastSuccess(__('Live stats refreshed.'));
    }

    /**
     * Silent stats poll for wire:poll — keeps the live connection count fresh
     * without a toast on every tick. No-op when stats are unavailable (fake
     * mode / relay unreachable).
     */
    public function pollStats(): void
    {
        $this->pullStats();
    }

    /**
     * Read live stats from the relay, persist the peak, and stash the current
     * connection count. Returns false when stats are unavailable.
     */
    private function pullStats(): bool
    {
        $this->authorize('view', $this->organization);

        $stats = RealtimeBackendFactory::make()->fetchStats($this->app);

        if ($stats === null) {
            return false;
        }

        $this->liveConnections = $stats['connections'];

        // Keep roughly ten minutes of 30s polls. Capped so a page left open all
        // day does not grow the Livewire payload without bound.
        $this->samples[] = ['at' => now()->format('H:i:s'), 'connections' => $stats['connections']];
        if (count($this->samples) > 20) {
            $this->samples = array_slice($this->samples, -20);
        }

        $this->app->forceFill([
            'peak_connections' => $stats['peakConnections'],
            'last_stats_at' => now(),
        ])->save();
        $this->app->refresh();

        return true;
    }

    /**
     * Clear the relay's high-water mark.
     *
     * Peak is what the tier is judged against, so a one-off spike (a load test,
     * a bad deploy loop) permanently skews every headroom reading until someone
     * clears it. Resetting is how an operator says "measure from now".
     */
    public function resetPeak(): void
    {
        $this->authorize('update', $this->organization);

        try {
            RealtimeBackendFactory::make()->resetPeakConnections($this->app);
        } catch (\Throwable $e) {
            $this->toastError(__('Could not reset the peak: :error', ['error' => $e->getMessage()]));

            return;
        }

        $this->app->forceFill(['peak_connections' => $this->liveConnections ?? 0])->save();
        $this->app->refresh();

        $this->toastSuccess(__('Peak reset — it now tracks from this moment.'));
    }

    public function startRename(): void
    {
        $this->authorize('update', $this->organization);
        $this->editName = $this->app->name;
        $this->editingName = true;
    }

    public function cancelRename(): void
    {
        $this->editingName = false;
        $this->editName = '';
    }

    public function saveName(UpdateRealtimeApp $action): void
    {
        $this->authorize('update', $this->organization);

        try {
            $action->rename($this->app, $this->editName);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->app->refresh();
        $this->cancelRename();
        $this->toastSuccess(__('Renamed.'));
    }

    /**
     * Pause or resume. Pausing closes the door on the relay and takes the app
     * off the bill; resuming re-publishes it. Both go through the same button
     * because they are the same decision seen from two sides.
     */
    public function togglePause(UpdateRealtimeApp $action): void
    {
        $this->authorize('update', $this->organization);

        $wasPaused = $this->app->status === RealtimeApp::STATUS_PAUSED;

        try {
            $wasPaused ? $action->resume($this->app) : $action->pause($this->app);
        } catch (\Throwable $e) {
            $this->toastError(__('The relay did not accept that: :error', ['error' => $e->getMessage()]));

            return;
        }

        $this->app->refresh();

        $this->toastSuccess($wasPaused
            ? __('Resuming — the app goes active in a moment.')
            : __('Paused. Connections are refused and it no longer counts toward your bill.'));
    }

    public function confirmRotate(): void
    {
        $this->authorize('update', $this->organization);
        $this->confirmingRotate = true;
        $this->dispatch('open-modal', 'realtime-rotate-modal');
    }

    public function cancelRotate(): void
    {
        $this->confirmingRotate = false;
        $this->dispatch('close-modal', 'realtime-rotate-modal');
    }

    public function rotateCredentials(UpdateRealtimeApp $action): void
    {
        $this->authorize('update', $this->organization);

        try {
            $action->rotateCredentials($this->app);
        } catch (\Throwable $e) {
            $this->toastError(__('Could not rotate credentials: :error', ['error' => $e->getMessage()]));

            return;
        }

        $this->app->refresh();
        $this->cancelRotate();
        $this->toastWarning(__('New credentials issued. Every existing client is disconnected until you ship the new key.'));
    }

    /**
     * Publish a demo event to this app. Mirrors the org console's playground so
     * the same round-trip proof is available from the page an operator lands on
     * when a site's broadcasting looks broken.
     */
    public function publishDemoEvent(RealtimePublisher $publisher): void
    {
        $this->authorize('update', $this->organization);

        $channel = trim($this->demoChannel) !== '' ? trim($this->demoChannel) : 'demo-channel';
        $event = trim($this->demoEvent) !== '' ? trim($this->demoEvent) : 'demo-event';

        try {
            $result = $publisher->publish($this->app, $channel, $event, [
                'message' => trim($this->demoMessage),
                'sent_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        if ($result['delivered'] === 0) {
            $this->toastWarning(__('Published, but nothing was subscribed to :channel.', ['channel' => $channel]));

            return;
        }

        $this->toastSuccess(trans_choice(
            'Delivered to :count subscriber.|Delivered to :count subscribers.',
            $result['delivered'],
            ['count' => $result['delivered']],
        ));
    }

    public function render(): View
    {
        // Sites that broadcast through this app, with their server so we can
        // link back to each site's Resources tab.
        $sites = SiteBinding::query()
            ->where('type', 'broadcasting')
            ->where('target_type', 'realtime_app')
            ->where('target_id', $this->app->id)
            ->with('site.server')
            ->get();

        return view('livewire.organizations.realtime-app', [
            'tier' => $this->app->tierConfig(),
            'tiers' => (array) config('realtime.tiers', []),
            'sites' => $sites,
            'canManage' => auth()->user()?->can('update', $this->organization) ?? false,
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Realtime'), 'href' => route('realtime.index'), 'icon' => 'signal'],
                ['label' => $this->app->name, 'icon' => 'signal'],
            ],
        ]);
    }
}
