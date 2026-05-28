<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\SiteBinding;
use App\Services\Deploy\SiteBindingManager;
use Illuminate\Support\Facades\Gate;

/**
 * Attach / provision / detach actions for a site's managed resource bindings,
 * surfaced on the Environment settings tab. State for the single shared modal
 * lives here; the per-type form fields are kept in one loose array so the
 * modal can render whichever shape the chosen type needs.
 */
trait ManagesSiteBindings
{
    /** database | scheduler | workers | redis | queue | storage */
    public string $bindingModalType = '';

    /** attach | provision */
    public string $bindingModalMode = 'attach';

    /** @var array<string, mixed> */
    public array $bindingForm = [];

    /** @var list<array{id: string, label: string}> */
    public array $bindingTargets = [];

    public function openBindingModal(string $type, string $mode = 'attach'): void
    {
        Gate::authorize('update', $this->site);

        $this->resetErrorBag();
        $this->bindingModalType = $type;
        $this->bindingModalMode = $mode === 'provision' ? 'provision' : 'attach';
        $this->bindingForm = $this->defaultBindingForm($type, $this->bindingModalMode);
        $this->bindingTargets = app(SiteBindingManager::class)->attachableTargets($this->site, $type);
        $this->dispatch('open-modal', 'site-binding-modal');
    }

    public function saveBinding(SiteBindingManager $manager): void
    {
        Gate::authorize('update', $this->site);

        try {
            if ($this->bindingModalMode === 'provision') {
                $manager->provisionNew($this->site, $this->bindingModalType, $this->bindingForm);
            } else {
                $manager->attachExisting($this->site, $this->bindingModalType, $this->bindingForm);
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site = $this->site->fresh() ?? $this->site;
        $this->dispatch('close-modal', 'site-binding-modal');
        $this->toastSuccess(__('Binding saved.'));
    }

    public function detachBinding(string $bindingId, SiteBindingManager $manager): void
    {
        Gate::authorize('update', $this->site);

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof SiteBinding) {
            return;
        }

        $manager->detach($binding);
        $this->site = $this->site->fresh() ?? $this->site;
        $this->toastSuccess(__('Binding detached.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultBindingForm(string $type, string $mode): array
    {
        return match (true) {
            $type === 'database' && $mode === 'provision' => ['engine' => 'mysql', 'name' => '', 'host' => '127.0.0.1'],
            $type === 'database' => ['target_id' => ''],
            $type === 'queue' => ['driver' => 'database'],
            $type === 'storage' => ['bucket' => '', 'access_key_id' => '', 'secret_access_key' => '', 'region' => '', 'url' => '', 'endpoint' => ''],
            default => [],
        };
    }
}
