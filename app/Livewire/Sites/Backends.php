<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\LoadBalancer;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBackend;
use App\Services\Sites\Backends\SiteBackendManager;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Add / remove load-balanced web backends for a site (multi-backend, the
 * substrate under rolling + canary). Nested section component embedded by
 * settings.blade.php. See docs/MULTI_BACKEND_SITES.md.
 */
class Backends extends Component
{
    use AuthorizesRequests;
    use DispatchesToastNotifications;

    public Server $server;

    public Site $site;

    /** Chosen on the first add (locked once a group exists). */
    public string $substrate = SiteBackendManager::SUBSTRATE_HAPROXY;

    public function mount(Server $server, Site $site): void
    {
        $this->authorize('view', $site);
        $this->server = $server;
        $this->site = $site;

        $substrate = $site->backendGroup()['substrate'] ?? null;
        if (is_string($substrate) && $substrate !== '') {
            $this->substrate = $substrate;
        }
    }

    /** Backends ordered primary-first, then by creation. */
    #[Computed]
    public function backends(): Collection
    {
        return $this->site->backends()
            ->with('server')
            ->get()
            ->sortBy([
                fn (SiteBackend $b): int => $b->isPrimary() ? 0 : 1,
                fn (SiteBackend $b): string => (string) $b->created_at,
            ])
            ->values();
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function group(): array
    {
        return $this->site->fresh()?->backendGroup() ?? [];
    }

    #[Computed]
    public function loadBalancer(): ?LoadBalancer
    {
        $id = (string) ($this->group['load_balancer_id'] ?? '');

        return $id !== '' ? LoadBalancer::query()->find($id) : null;
    }

    /** True while any backend is still coming up (drives the live refresh). */
    #[Computed]
    public function isConverging(): bool
    {
        return $this->backends->contains(
            fn (SiteBackend $b): bool => ! in_array($b->state, [SiteBackend::STATE_ACTIVE, SiteBackend::STATE_ERRORED], true)
        );
    }

    public function canManage(): bool
    {
        return $this->site->server?->hostCapabilities()->supportsSsh() === true;
    }

    public function addBackend(SiteBackendManager $manager): void
    {
        $this->authorize('update', $this->site);

        if (! $this->canManage()) {
            $this->toastError(__('Backends are only available for VM-hosted sites.'));

            return;
        }

        try {
            $manager->addBackend($this->site->fresh(), ['substrate' => $this->substrate]);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->refreshState();
        $this->toastSuccess(__('Provisioning a new backend — it joins the pool once ready.'));
    }

    public function removeBackend(string $id, SiteBackendManager $manager): void
    {
        $this->authorize('update', $this->site);

        $backend = $this->site->backends()->whereKey($id)->first();
        if ($backend === null) {
            return;
        }

        try {
            $manager->removeBackend($backend, auth()->user());
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->refreshState();
        $this->toastSuccess(__('Draining and removing the backend.'));
    }

    /** Polled by the view while backends are converging. */
    public function refreshState(): void
    {
        unset($this->backends, $this->group, $this->loadBalancer, $this->isConverging);
    }

    public function render(): View
    {
        return view('livewire.sites.backends');
    }
}
