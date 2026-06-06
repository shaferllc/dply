<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\FixSiteBindingConnectivityJob;
use App\Jobs\ValidateBindingConnectivityJob;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
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
    public ?string $fixBindingId = null;

    /** The in-flight fix run id — when set, the fix modal shows live progress in place. */
    public ?string $fixBindingRunId = null;

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
     * Open the fix modal for a binding: stash the binding id and clear any
     * progress from a previous run so the options (not stale output) render.
     */
    public function startFixBinding(string $bindingId): void
    {
        $this->fixBindingId = $bindingId;
        $this->fixBindingRunId = null;
    }

    /**
     * Make an UNREACHABLE binding reachable: optionally re-point it at the right
     * backend, then enable remote access + firewall the consumer's /32 and
     * re-probe. Streams live progress inside the fix modal (the modal stays open
     * and switches from the options view to the console output).
     */
    public function fixBindingConnectivity(string $bindingId, ?string $repointTargetId = null): void
    {
        Gate::authorize('update', $this->site);

        $binding = SiteBinding::query()->where('site_id', $this->site->id)->whereKey($bindingId)->first();
        if (! $binding instanceof SiteBinding) {
            return;
        }
        if (! method_exists($this, 'seedQueuedConsoleAction') || ! method_exists($this, 'watchConsoleAction')) {
            $this->toastError(__('Connectivity fixes are available from the deploy hub.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('binding_connectivity_fix', __('Fixing connectivity'));
        FixSiteBindingConnectivityJob::dispatch(
            (string) $run->id,
            (string) $this->site->id,
            (string) $binding->id,
            $repointTargetId !== '' ? $repointTargetId : null,
            (string) (auth()->id() ?? '') ?: null,
        );

        // Switch the (still-open) modal to the live-progress view.
        $this->fixBindingRunId = (string) $run->id;
        $this->watchConsoleAction(
            $run,
            __('Connectivity fix applied — re-probing the connection.'),
            __('The connectivity fix could not finish — check the console.'),
        );
    }

    /**
     * Backends this binding could be re-pointed at (same org, same resource
     * type), for the Fix-connectivity modal's "wrong target?" picker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bindingFixCandidates(string $bindingId): array
    {
        $binding = SiteBinding::query()->where('site_id', $this->site->id)->whereKey($bindingId)->first();
        if (! $binding instanceof SiteBinding) {
            return [];
        }

        $orgServerIds = Server::query()
            ->where('organization_id', $this->site->server?->organization_id)
            ->pluck('id');

        $row = fn ($r): array => [
            'id' => (string) $r->id,
            'label' => $r->name ?: ucfirst((string) $r->engine),
            'engine' => (string) $r->engine,
            'server' => $r->server?->name,
            'host' => $r->server?->private_ip_address,
        ];

        return match ($binding->type) {
            'database' => ServerDatabase::query()->whereIn('server_id', $orgServerIds)->with('server')->get()->map($row)->values()->all(),
            'redis' => ServerCacheService::query()->whereIn('server_id', $orgServerIds)
                ->whereIn('engine', ServerCacheService::FAMILY_REDIS_ENGINES)->with('server')->get()->map($row)->values()->all(),
            default => [],
        };
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
            $type === 'cache' => ['driver' => 'database'],
            $type === 'storage' => $this->defaultStorageBindingForm(),
            default => [],
        };
    }

    /**
     * Default object-storage form, seeded to the first provider and its first
     * region so the modal opens on a usable preset.
     *
     * @return array<string, mixed>
     */
    private function defaultStorageBindingForm(): array
    {
        $providers = (array) config('object_storage.providers', []);
        $provider = (string) (array_key_first($providers) ?? 'aws_s3');
        $regions = array_keys((array) ($providers[$provider]['regions'] ?? []));

        return [
            'provider' => $provider,
            'region' => $regions[0] ?? '',
            'bucket' => '',
            'access_key_id' => '',
            'secret_access_key' => '',
            'url' => '',
            'endpoint' => '',
        ];
    }

    /**
     * When the storage provider changes, reset the region to that provider's
     * first known region so the derived endpoint stays consistent — an AWS
     * region left selected after switching to Hetzner would build a bogus
     * endpoint. Custom providers carry no region list, so the field clears.
     */
    public function updatedBindingForm(mixed $value, ?string $key = null): void
    {
        if ($key !== 'provider' || $this->bindingModalType !== 'storage') {
            return;
        }

        $regions = array_keys((array) config('object_storage.providers.'.$value.'.regions', []));
        $this->bindingForm['region'] = $regions[0] ?? '';
        $this->bindingForm['endpoint'] = '';
    }
}
