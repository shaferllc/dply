<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Livewire;

use App\Modules\Realtime\Actions\DeleteRealtimeApp;
use App\Modules\Realtime\Actions\UpdateRealtimeApp;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Models\RealtimeApp;
use App\Models\SiteBinding;
use App\Modules\Realtime\Services\RealtimeBackendFactory;
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

    public function mount(Organization $organization, RealtimeApp $realtimeApp): void
    {
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

    public function deleteApp(DeleteRealtimeApp $action)
    {
        $this->authorize('update', $this->organization);

        $action->handle($this->app);

        $this->toastSuccess(__('Broadcasting app deleted. It no longer counts toward your bill.'));

        return $this->redirect(route('organizations.realtime', $this->organization), navigate: true);
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
        $this->app->forceFill([
            'peak_connections' => $stats['peakConnections'],
            'last_stats_at' => now(),
        ])->save();
        $this->app->refresh();

        return true;
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
                ['label' => $this->organization->name, 'href' => route('organizations.show', $this->organization), 'icon' => 'building-office-2'],
                ['label' => __('Realtime'), 'href' => route('organizations.realtime', $this->organization), 'icon' => 'signal'],
                ['label' => $this->app->name, 'icon' => 'signal'],
            ],
            'orgShellSection' => 'realtime',
        ]);
    }
}
