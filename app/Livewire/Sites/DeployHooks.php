<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Services\Deploy\ServerlessDeployHookRunner;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Manage a serverless function's deploy hooks — shell scripts run at the
 * before-build / after-build / after-deploy phases of the artifact build
 * by {@see ServerlessDeployHookRunner}. Embedded on the Deployments tab.
 */
class DeployHooks extends Component
{
    use DispatchesToastNotifications;

    public string $siteId = '';

    public bool $formOpen = false;

    public string $newPhase = SiteDeployHook::PHASE_AFTER_CLONE;

    public string $newScript = '';

    public int $newTimeout = 900;

    public int $newOrder = 0;

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->siteId = $site->id;
    }

    private function site(): Site
    {
        return Site::findOrFail($this->siteId);
    }

    public function addHook(): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $this->validate([
            'newPhase' => 'required|in:before_clone,after_clone,after_activate',
            'newScript' => 'required|string|max:16000',
            'newTimeout' => 'required|integer|min:30|max:3600',
            'newOrder' => 'integer|min:0|max:999',
        ]);

        SiteDeployHook::query()->create([
            'site_id' => $site->id,
            'phase' => $this->newPhase,
            'script' => $this->newScript,
            'sort_order' => $this->newOrder,
            'timeout_seconds' => $this->newTimeout,
        ]);

        $this->reset('newScript', 'newOrder', 'formOpen');
        $this->toastSuccess(__('Deploy hook added.'));
    }

    public function deleteHook(string $id): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        SiteDeployHook::query()
            ->where('site_id', $site->id)
            ->whereKey($id)
            ->delete();

        $this->toastSuccess(__('Deploy hook removed.'));
    }

    public function render(): View
    {
        return view('livewire.sites.deploy-hooks', [
            'phaseLabels' => ServerlessDeployHookRunner::PHASE_LABELS,
            'hooksByPhase' => SiteDeployHook::query()
                ->where('site_id', $this->siteId)
                ->orderBy('sort_order')
                ->get()
                ->groupBy('phase'),
        ]);
    }
}
