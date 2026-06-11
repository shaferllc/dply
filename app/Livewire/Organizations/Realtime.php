<?php

declare(strict_types=1);

namespace App\Livewire\Organizations;

use App\Actions\Realtime\DeleteRealtimeApp;
use App\Actions\Realtime\UpdateRealtimeApp;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Models\RealtimeApp;
use App\Models\SiteBinding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org-level dashboard for managed realtime (broadcasting) apps. Lists the
 * organization's apps with status, tier, usage and price, and lets admins move
 * an app between connection tiers or tear it down. Apps are provisioned from a
 * site's broadcasting binding; this is the place to manage them after the fact.
 */
#[Layout('layouts.app')]
class Realtime extends Component
{
    use DispatchesToastNotifications;

    public Organization $organization;

    /** The app currently open in the tier-change modal, if any. */
    public ?string $managingAppId = null;

    public string $selectedTier = '';

    /** Re-consent required when a tier change raises the monthly price. */
    public bool $confirmTierCharge = false;

    /** The app currently open in the delete-confirmation modal, if any. */
    public ?string $deletingAppId = null;

    public function mount(Organization $organization): void
    {
        $this->organization = $organization;
        $this->authorize('view', $organization);
    }

    public function startTierChange(string $appId): void
    {
        $app = $this->findApp($appId);
        $this->managingAppId = $app->id;
        $this->selectedTier = $app->tierSlug();
        $this->confirmTierCharge = false;
        $this->dispatch('open-modal', 'realtime-tier-modal');
    }

    public function cancelTierChange(): void
    {
        $this->reset(['managingAppId', 'selectedTier', 'confirmTierCharge']);
        $this->dispatch('close-modal', 'realtime-tier-modal');
    }

    public function changeTier(UpdateRealtimeApp $action): void
    {
        $this->authorize('update', $this->organization);

        $app = $this->findApp((string) $this->managingAppId);
        $tiers = (array) config('realtime.tiers', []);

        if (! array_key_exists($this->selectedTier, $tiers)) {
            $this->toastError(__('Pick a connection tier.'));

            return;
        }

        if ($this->selectedTier === $app->tierSlug()) {
            $this->toastWarning(__('That app is already on the :tier tier.', ['tier' => $app->tierConfig()['label']]));

            return;
        }

        // Require explicit re-consent only when the change raises the bill.
        $newPriceCents = (int) ($tiers[$this->selectedTier]['price_cents'] ?? 0);
        if ($newPriceCents > $app->priceCents() && ! $this->confirmTierCharge) {
            $this->toastError(__('Confirm the new monthly charge to upgrade.'));

            return;
        }

        $action->changeTier($app, $this->selectedTier);

        $this->toastSuccess(__('Broadcasting app moved to the :tier tier. Your workspace bill updates to match.', [
            'tier' => (string) ($tiers[$this->selectedTier]['label'] ?? $this->selectedTier),
        ]));

        $this->cancelTierChange();
    }

    public function confirmDelete(string $appId): void
    {
        $this->deletingAppId = $this->findApp($appId)->id;
        $this->dispatch('open-modal', 'realtime-delete-modal');
    }

    public function cancelDelete(): void
    {
        $this->reset('deletingAppId');
        $this->dispatch('close-modal', 'realtime-delete-modal');
    }

    public function deleteApp(DeleteRealtimeApp $action): void
    {
        $this->authorize('update', $this->organization);

        $app = $this->findApp((string) $this->deletingAppId);
        $action->handle($app);

        $this->toastSuccess(__('Broadcasting app deleted. It no longer counts toward your bill.'));
        $this->cancelDelete();
    }

    /** Resolve an app id, scoped to this organization (404 otherwise). */
    private function findApp(string $appId): RealtimeApp
    {
        return $this->organization->realtimeApps()->findOrFail($appId);
    }

    public function render(): View
    {
        $apps = $this->organization->realtimeApps()
            ->orderByDesc('created_at')
            ->get();

        // Sites that depend on each app (broadcasting bindings), so the UI can
        // warn before deleting an app a live site still points at.
        $siteUsage = SiteBinding::query()
            ->where('type', 'broadcasting')
            ->where('target_type', 'realtime_app')
            ->whereIn('target_id', $apps->pluck('id'))
            ->with('site:id,name,organization_id')
            ->get()
            ->groupBy('target_id');

        $activeApps = $apps->where('status', RealtimeApp::STATUS_ACTIVE);
        $monthlyCents = $activeApps->sum(fn (RealtimeApp $app): int => $app->priceCents());

        return view('livewire.organizations.realtime', [
            'apps' => $apps,
            'siteUsage' => $siteUsage,
            'tiers' => (array) config('realtime.tiers', []),
            'activeCount' => $activeApps->count(),
            'monthlyCents' => $monthlyCents,
            'managingApp' => $this->managingAppId !== null ? $apps->firstWhere('id', $this->managingAppId) : null,
            'deletingApp' => $this->deletingAppId !== null ? $apps->firstWhere('id', $this->deletingAppId) : null,
            'deletingAppSites' => $this->deletingAppId !== null
                ? ($siteUsage->get($this->deletingAppId) ?? new Collection)
                : new Collection,
            'featureActive' => Feature::for($this->organization)->active('surface.realtime'),
            'canManage' => auth()->user()?->can('update', $this->organization) ?? false,
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $this->organization->name, 'href' => route('organizations.show', $this->organization), 'icon' => 'building-office-2'],
                ['label' => __('Realtime'), 'icon' => 'signal'],
            ],
            'orgShellSection' => 'realtime',
        ]);
    }
}
