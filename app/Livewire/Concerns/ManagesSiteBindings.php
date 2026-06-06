<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\FixSiteBindingConnectivityJob;
use App\Jobs\InstallCacheServiceJob;
use App\Jobs\ValidateBindingConnectivityJob;
use App\Models\ObjectStorageCredential;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\SiteBinding;
use App\Services\Deploy\SiteBindingManager;
use App\Support\Servers\CacheEngineAvailability;
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

    /**
     * Switch the open modal between attach and provision without closing it, so
     * a single dropdown entry (e.g. "Object storage") can offer both. Re-seeds
     * the form to the new mode's defaults (provision narrows the provider list,
     * so a stale attach-only provider would otherwise leak through).
     */
    public function setBindingMode(string $mode): void
    {
        $mode = $mode === 'provision' ? 'provision' : 'attach';
        if ($mode === $this->bindingModalMode) {
            return;
        }

        $this->bindingModalMode = $mode;
        $this->bindingForm = $this->defaultBindingForm($this->bindingModalType, $mode);
        $this->bindingTargets = app(SiteBindingManager::class)->attachableTargets($this->site, $this->bindingModalType);
        $this->resetErrorBag();
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

    public function verifyBinding(string $bindingId): void
    {
        Gate::authorize('update', $this->site);

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof SiteBinding) {
            return;
        }

        $this->validateBindingConnectivity($binding);
    }

    /**
     * Install Redis or Valkey on the site's server directly from the binding
     * modal, so operators don't have to leave to the server Caches workspace.
     * Mirrors the core of WorkspaceCaches::installCacheService.
     */
    public function installCacheOnServer(string $engine): void
    {
        Gate::authorize('update', $this->site);

        if (! in_array($engine, ['redis', 'valkey'], true)) {
            return;
        }

        if (CacheEngineAvailability::isComingSoon($engine)) {
            $this->toastError(__(':engine is not available yet.', ['engine' => ucfirst($engine)]));
            return;
        }

        $server = $this->site->server;
        if (! $server instanceof Server) {
            return;
        }

        $existing = ServerCacheService::query()
            ->where('server_id', $server->id)
            ->whereIn('engine', ['redis', 'valkey', 'keydb', 'dragonfly'])
            ->first();

        if ($existing !== null && ! in_array($existing->status, [
            ServerCacheService::STATUS_PENDING,
            ServerCacheService::STATUS_FAILED,
            ServerCacheService::STATUS_STOPPED,
        ], true)) {
            $this->toastError(__(':engine is already installing or running on this server.', ['engine' => $existing->engine]));
            return;
        }

        $row = $existing ?? ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => $engine,
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_PENDING,
            'port' => ServerCacheService::defaultPortFor($engine),
        ]);

        InstallCacheServiceJob::dispatch($row->id);

        $this->dispatch('close-modal', 'site-binding-modal');
        $this->toastSuccess(__('Installing :engine on this server — it will appear here once ready.', ['engine' => ucfirst($engine)]));
    }

    /**
     * Switch the server's existing redis-family service to a different engine
     * (e.g. Redis → Valkey). Mirrors WorkspaceCaches via SwitchCacheServiceJob.
     */
    public function switchCacheOnServer(string $targetEngine): void
    {
        Gate::authorize('update', $this->site);

        if (! in_array($targetEngine, ['redis', 'valkey'], true)) {
            return;
        }

        if (CacheEngineAvailability::isComingSoon($targetEngine)) {
            $this->toastError(__(':engine is not available yet.', ['engine' => ucfirst($targetEngine)]));
            return;
        }

        $server = $this->site->server;
        if (! $server instanceof Server) {
            return;
        }

        $existing = ServerCacheService::query()
            ->where('server_id', $server->id)
            ->whereIn('engine', ['redis', 'valkey', 'keydb', 'dragonfly'])
            ->first();

        if (! $existing instanceof ServerCacheService) {
            $this->toastError(__('No redis-family service found on this server to switch from.'));
            return;
        }

        if ($existing->engine === $targetEngine) {
            $this->toastError(__(':engine is already the active engine.', ['engine' => ucfirst($targetEngine)]));
            return;
        }

        \App\Jobs\SwitchCacheServiceJob::dispatch($existing->id, $targetEngine);

        $this->dispatch('close-modal', 'site-binding-modal');
        $this->toastSuccess(__('Switching to :engine — it will appear here once ready.', ['engine' => ucfirst($targetEngine)]));
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
            $type === 'cache' => $this->defaultCacheBindingForm(),
            $type === 'session' => $this->defaultSessionBindingForm(),
            $type === 'storage' => $this->defaultStorageBindingForm($mode),
            default => [],
        };
    }

    /**
     * Seed the session attach form. Prefills from an existing session binding's
     * stored config so re-opening "Configure" keeps the current values; a blank
     * field means "use the framework default" for that key.
     *
     * @return array<string, string>
     */
    private function defaultCacheBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'cache');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        $driver = (string) ($cfg['driver'] ?? '');
        $prefix = (string) ($cfg['prefix'] ?? '');

        // No binding yet → seed driver + prefix from the app's current env so
        // configuring the cache binding adopts the loose CACHE_STORE/CACHE_PREFIX
        // rather than resetting them or leaving a duplicate variable behind.
        if ($existing === null) {
            $env = app(\App\Services\Deploy\DeploymentSecretInventory::class)
                ->effectiveEnvironmentMapForSite($this->site);
            if ($driver === '') {
                $driver = strtolower(trim((string) ($env['CACHE_STORE'] ?? $env['CACHE_DRIVER'] ?? '')));
            }
            if ($prefix === '') {
                $prefix = trim((string) ($env['CACHE_PREFIX'] ?? ''));
            }
        }

        if (! in_array($driver, ['database', 'redis', 'file', 'array'], true)) {
            $driver = 'database';
        }

        return [
            'driver' => $driver,
            'prefix' => $prefix,
        ];
    }

    private function defaultSessionBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'session');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'driver' => (string) ($cfg['driver'] ?? ''),
            'lifetime' => (string) ($cfg['lifetime'] ?? ''),
            'encrypt' => (string) ($cfg['encrypt'] ?? ''),
            'path' => (string) ($cfg['path'] ?? ''),
            'domain' => (string) ($cfg['domain'] ?? ''),
            'secure_cookie' => (string) ($cfg['secure_cookie'] ?? ''),
            'http_only' => (string) ($cfg['http_only'] ?? ''),
            'same_site' => (string) ($cfg['same_site'] ?? ''),
        ];
    }

    /**
     * Default object-storage form, seeded to the first provider and its first
     * region so the modal opens on a usable preset. In provision mode the
     * provider list is narrowed to those dply can actually create a bucket on.
     *
     * @return array<string, mixed>
     */
    private function defaultStorageBindingForm(string $mode = 'attach'): array
    {
        $providers = (array) config('object_storage.providers', []);
        if ($mode === 'provision') {
            $providers = array_filter($providers, fn ($p) => (bool) ($p['provision'] ?? false));
        }
        $provider = (string) (array_key_first($providers) ?? 'aws_s3');
        $regions = array_keys((array) ($providers[$provider]['regions'] ?? []));

        [$keySource, $cloudCredId] = $this->storageKeySourceDefault($provider, $mode);

        return [
            'provider' => $provider,
            'region' => $regions[0] ?? '',
            'bucket' => '',
            'access_key_id' => '',
            'secret_access_key' => '',
            'url' => '',
            'endpoint' => '',
            // Saved-credential reuse: when set, the manager loads the keys from
            // an ObjectStorageCredential instead of the typed fields.
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
            // Auto-create flow: 'api' has dply mint the S3 keys via the cloud
            // token in $provider_credential_id; 'manual' uses saved/typed keys.
            'key_source' => $keySource,
            'provider_credential_id' => $cloudCredId,
        ];
    }

    /**
     * Default key source for a storage provider+mode: API-managed creation only
     * in provision mode, only for api_managed providers, and only when the org
     * has a matching cloud token. Otherwise fall back to manual keys.
     *
     * @return array{0: string, 1: string}  [key_source, provider_credential_id]
     */
    private function storageKeySourceDefault(string $provider, string $mode): array
    {
        if ($mode !== 'provision') {
            return ['manual', ''];
        }
        if (! (bool) config('object_storage.providers.'.$provider.'.api_managed', false)) {
            return ['manual', ''];
        }

        $creds = $this->cloudCredentialsForStorage($provider);
        if ($creds === []) {
            return ['manual', ''];
        }

        return ['api', (string) $creds[0]['id']];
    }

    /**
     * Cloud API-token credentials the org holds for a storage provider's
     * api_provider (e.g. digitalocean), powering the auto-create flow + picker.
     *
     * @return list<array{id: string, label: string}>
     */
    public function cloudCredentialsForStorage(string $storageProvider): array
    {
        $apiProvider = (string) config('object_storage.providers.'.$storageProvider.'.api_provider', '');
        if ($apiProvider === '') {
            return [];
        }

        return ProviderCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $apiProvider)
            ->orderBy('created_at')
            ->get()
            ->map(fn (ProviderCredential $c): array => [
                'id' => (string) $c->id,
                'label' => (string) ($c->name ?: ucfirst($apiProvider).' token'),
            ])
            ->all();
    }

    /**
     * Saved object-storage credentials the site's org can reuse for $provider,
     * for the binding modal's "Use saved keys" picker.
     *
     * @return list<array{id: string, label: string, region: string, endpoint: string}>
     */
    public function storageCredentialsFor(string $provider): array
    {
        return ObjectStorageCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (ObjectStorageCredential $c): array => [
                'id' => (string) $c->id,
                'label' => (string) $c->name,
                'region' => (string) ($c->region ?? ''),
                'endpoint' => (string) ($c->endpoint ?? ''),
            ])
            ->all();
    }

    public function deleteStorageCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = ObjectStorageCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof ObjectStorageCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved storage credential removed.'));
    }

    /**
     * When the storage provider changes, reset the region to that provider's
     * first known region so the derived endpoint stays consistent — an AWS
     * region left selected after switching to Hetzner would build a bogus
     * endpoint. Custom providers carry no region list, so the field clears.
     * Also clears any saved-credential selection (it's provider-specific), and
     * when a saved credential is picked, pre-fills its stored region/endpoint.
     */
    public function updatedBindingForm(mixed $value, ?string $key = null): void
    {
        if ($this->bindingModalType !== 'storage') {
            return;
        }

        if ($key === 'provider') {
            $regions = array_keys((array) config('object_storage.providers.'.$value.'.regions', []));
            $this->bindingForm['region'] = $regions[0] ?? '';
            $this->bindingForm['endpoint'] = '';
            $this->bindingForm['credential_id'] = '';

            // Re-derive the auto-create default for the new provider (DO can mint
            // keys, Hetzner can't), so switching provider flips key entry on/off.
            [$keySource, $cloudCredId] = $this->storageKeySourceDefault((string) $value, $this->bindingModalMode);
            $this->bindingForm['key_source'] = $keySource;
            $this->bindingForm['provider_credential_id'] = $cloudCredId;

            return;
        }

        if ($key === 'credential_id' && is_string($value) && $value !== '') {
            $provider = (string) ($this->bindingForm['provider'] ?? '');
            $cred = ObjectStorageCredential::query()
                ->where('organization_id', $this->site->organization_id)
                ->where('provider', $provider)
                ->whereKey($value)
                ->first();

            if ($cred instanceof ObjectStorageCredential) {
                if (filled($cred->region)) {
                    $this->bindingForm['region'] = (string) $cred->region;
                }
                if (filled($cred->endpoint)) {
                    $this->bindingForm['endpoint'] = (string) $cred->endpoint;
                }
            }
        }
    }
}
