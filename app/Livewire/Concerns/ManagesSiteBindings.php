<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\ValidateBindingConnectivityJob;
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
            $binding = $this->bindingModalMode === 'provision'
                ? $manager->provisionNew($this->site, $this->bindingModalType, $this->bindingForm)
                : $manager->attachExisting($this->site, $this->bindingModalType, $this->bindingForm);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site = $this->site->fresh() ?? $this->site;
        $this->dispatch('close-modal', 'site-binding-modal');

        $name = $binding->name ?: $this->bindingModalType;

        // Attaching may have stripped conflicting keys from the .env cache (the
        // binding "adopts" its connection variables). On hosts with a server
        // .env, push so the live file drops those overrides too; otherwise just
        // confirm. autoPushAfterCacheMutation lives on ManagesSiteEnvironment —
        // present on the deploy hub, absent on plain Settings.
        if (method_exists($this, 'autoPushAfterCacheMutation')) {
            $this->autoPushAfterCacheMutation(__('Connected :name — its variables now manage the connection.', ['name' => $name]));
        } else {
            $this->toastSuccess(__('Connected :name.', ['name' => $name]));
        }

        $this->validateBindingConnectivity($binding);
    }

    /**
     * Fire a connectivity probe from the site's server to the resource the
     * binding points at, so "connect a resource" actually confirms the server
     * can reach it. Database/redis only (they carry a host:port); surfaced via
     * the console banner. Requires the host component's console-action plumbing
     * (present on the deploy hub), so it's feature-detected.
     */
    private function validateBindingConnectivity(SiteBinding $binding): void
    {
        if (! in_array($binding->type, ['database', 'redis'], true)) {
            return;
        }
        if (! method_exists($this, 'seedQueuedConsoleAction') || ! method_exists($this, 'watchConsoleAction')) {
            return;
        }

        $run = $this->seedQueuedConsoleAction('binding_validate', __('Validating connection'));

        ValidateBindingConnectivityJob::dispatch(
            (string) $run->id,
            (string) $this->site->id,
            (string) $binding->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('Connection verified — the server can reach :name.', ['name' => $binding->name ?: $binding->type]),
            __('Could not reach :name from the server — check it allows connections from this server.', ['name' => $binding->name ?: $binding->type]),
        );
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
            $type === 'redis' => ['target_id' => ''],
            $type === 'queue' => ['driver' => 'database'],
            $type === 'storage' => ['bucket' => '', 'access_key_id' => '', 'secret_access_key' => '', 'region' => '', 'url' => '', 'endpoint' => ''],
            default => [],
        };
    }
}
