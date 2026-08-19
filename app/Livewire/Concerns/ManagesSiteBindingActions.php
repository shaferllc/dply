<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\ServerProvider;
use App\Jobs\FixSiteBindingConnectivityJob;
use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Models\AiCredential;
use App\Models\CaptchaCredential;
use App\Models\CloudDatabase;
use App\Models\ConnectedAppCredential;
use App\Models\ConsoleAction;
use App\Models\ErrorTrackingCredential;
use App\Models\LogDrainCredential;
use App\Models\OauthCredential;
use App\Models\ObjectStorageCredential;
use App\Models\PaymentCredential;
use App\Models\ProviderCredential;
use App\Models\SearchCredential;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\ServerManageAction;
use App\Models\SiteBinding;
use App\Models\SmsCredential;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Modules\Database\Actions\CreateDedicatedDatabaseVm;
use App\Modules\Database\Actions\CreateDedicatedDockerDatabaseVm;
use App\Modules\Database\Actions\CreateDedicatedRedisVm;
use App\Modules\Database\Backends\DatabaseRouter;
use App\Modules\Database\Jobs\ProvisionDedicatedDatabaseVmJob;
use App\Modules\Database\Jobs\ProvisionDedicatedDockerDatabaseVmJob;
use App\Modules\Database\Jobs\ProvisionDedicatedRedisVmJob;
use App\Modules\Database\Jobs\ResizeManagedDatabaseJob;
use App\Modules\Database\Support\DedicatedDatabaseVm;
use App\Modules\Database\Support\DockerDatabase;
use App\Modules\Database\Support\ServerlessDatabaseVendors;
use App\Modules\Deploy\Services\LookoutProvisioner;
use App\Modules\Deploy\Services\SiteBindingManager;
use App\Services\Servers\ServerManageScriptQueuer;
use App\Support\Providers\ProviderAuthFailure;
use App\Support\Servers\DatabaseNameGenerator;
use App\Support\Servers\DedicatedVmPlacement;
use App\Support\Servers\ManagedDatabaseCatalogFailure;
use App\Support\Servers\ManagedDatabaseRegionCatalog;
use App\Support\Servers\ManagedDatabaseSizeCatalog;
use App\Support\Servers\ProviderManagedDatabaseRegion;
use App\Support\Servers\ProvisioningDigest;
use App\Support\Sites\ConnectedAppEnvPaste;
use App\Support\Sites\ManagedDatabaseProvisionConsole;
use App\Support\Sites\SiteBindingCatalog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Throwable;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 *
 * @method \App\Models\ConsoleAction seedQueuedConsoleAction(string $kind, ?string $label = null)
 * @method void watchConsoleAction(\App\Models\ConsoleAction $run, string $successToast, ?string $failureToast = null)
 * @method void toastError(string|\Stringable $message)
 * @method void toastSuccess(string|\Stringable $message)
 * @method void openConfirmActionModal(string $method, mixed $arguments = [], string $title = 'Confirm action', string $message = 'Are you sure?', string $confirmLabel = 'Confirm', bool $destructive = false)
 */
trait ManagesSiteBindingActions
{
    /** Site-scoped console run while Docker Engine is installing from the binding modal. */
    public ?string $dockerInstallRunId = null;

    public function openBindingModal(string $type, string $mode = 'attach', ?string $bindingId = null): void
    {
        Gate::authorize('update', $this->site);

        // A primary already mid-provision (or failed) is easy to miss — the
        // resources card used to say "Connected". Open that row's status
        // instead of a second Provision form that only warns about a name clash.
        if ($bindingId === null && $mode === 'provision' && in_array($type, ['database', 'redis'], true)) {
            $inFlight = $this->inFlightPrimaryBinding($type);
            if ($inFlight instanceof SiteBinding) {
                $this->openBindingInfoModal((string) $inFlight->id);

                return;
            }
        }

        if ($type === 'queue'
            && SiteBindingCatalog::missingNeedsAny($this->site->bindings, ['redis', 'database'])) {
            $this->toastError(__('Attach Redis or a database before configuring the queue.'));

            return;
        }

        $this->resetErrorBag();
        $this->connectedAppPasteNote = null;
        $this->bindingModalType = $type;
        $this->bindingModalMode = $mode === 'provision' ? 'provision' : 'attach';
        $this->bindingModalBindingId = null;
        $this->bindingEdit = null;
        $this->bindingForm = $this->defaultBindingForm($type, $this->bindingModalMode === 'provision' ? 'provision' : 'attach');

        // Editing a specific existing binding (multi-instance types like storage):
        // pre-fill the non-secret fields from that row. Secrets are never echoed,
        // so the operator re-supplies keys (or reuses a saved credential).
        // Remake/repair still opens as provision; every other id-bearing open
        // is an edit of that row — not a fresh attach/provision wizard.
        if ($bindingId !== null) {
            $this->seedBindingFormForEdit($type, $bindingId);
            if ($this->bindingModalMode !== 'provision') {
                $this->bindingModalMode = 'edit';
            }
        }

        $this->bindingTargets = app(SiteBindingManager::class)->attachableTargets($this->site, $type);

        // A dedicated-DB-VM placement needs a size list (provider/region
        // specific); fetch it up front so the picker is ready after they choose.
        $this->dedicatedVmSizes = [];
        $this->dedicatedVmSizeError = null;
        if (in_array($type, ['database', 'redis'], true) && $this->bindingModalMode === 'provision') {
            $this->loadDedicatedVmSizes();
        }

        $this->dispatch('open-modal', 'site-binding-modal');
    }

    /**
     * Regenerate button on the provision-database form — mirrors the server
     * create wizard's Identity card. Excludes the current value so a click
     * always changes what the operator sees.
     */
    public function regenerateBindingDatabaseName(): void
    {
        Gate::authorize('update', $this->site);

        $this->bindingForm['name'] = DatabaseNameGenerator::random((string) ($this->bindingForm['name'] ?? ''));
        $this->resetErrorBag('bindingForm.name');
    }

    /**
     * Changing engine can make the current placement illegal (e.g. on-box
     * after switching to Redis). Clear it so the operator picks again — size
     * and vendor fields must not linger from the previous card.
     */
    public function updatedBindingFormEngine(mixed $value): void
    {
        if ($this->bindingModalType !== 'database' || $this->bindingModalMode !== 'provision') {
            return;
        }

        $engine = strtolower(trim((string) $value));
        $placement = (string) ($this->bindingForm['placement'] ?? '');
        $compatible = collect($this->databasePlacements())
            ->filter(fn (array $p): bool => $engine === '' || in_array($engine, $p['engines'] ?? [], true))
            ->pluck('key')
            ->all();

        if ($placement !== '' && $compatible !== [] && ! in_array($placement, $compatible, true)) {
            $this->bindingForm['placement'] = '';
        }
    }

    /**
     * Populate {@see $dedicatedVmSizes} from the customer-connected create
     * catalog for the app server's provider + region. A stale/missing
     * credential falls back to any org credential, then the platform catalog
     * token. Failures surface in the size picker after that placement is chosen.
     */
    private function loadDedicatedVmSizes(): void
    {
        $this->dedicatedVmSizeError = null;
        $this->dedicatedVmRegion = null;
        $this->dedicatedVmRequestedRegion = null;
        $server = $this->site->server;
        if ($server === null || ! DedicatedDatabaseVm::eligible($server) || $this->site->organization === null) {
            return;
        }

        try {
            $placement = DedicatedVmPlacement::for($server, $this->site->organization);
            $this->dedicatedVmSizes = $placement['sizes'];
            $this->dedicatedVmRegion = $placement['region'] !== '' ? $placement['region'] : null;
            $this->dedicatedVmRequestedRegion = $placement['requested_region'] !== ''
                ? $placement['requested_region']
                : null;

            // Preselect the first size so the dedicated card has a valid value
            // the moment it's chosen (the field is shared via bindingForm).
            if ($this->dedicatedVmSizes !== [] && ($this->bindingForm['vm_size'] ?? '') === '') {
                $this->bindingForm['vm_size'] = $this->dedicatedVmSizes[0]['value'];
            }

            if ($this->dedicatedVmSizes === []) {
                $this->dedicatedVmSizeError = $placement['error']
                    ?: __('No sizes available for this provider/region.');
            }
        } catch (Throwable $e) {
            $this->dedicatedVmSizes = [];
            $this->dedicatedVmSizeError = $e->getMessage();
        }
    }

    /**
     * Provision a brand-new database server on the customer's connected
     * provider and attach it. Runs in the component layer because the
     * customer-connected create pipeline is driven by a Livewire Form object.
     */
    public function provisionDedicatedDatabaseVm(): void
    {
        Gate::authorize('update', $this->site);
        $this->releaseFailedBindingBeforeReplace();

        try {
            app(CreateDedicatedDatabaseVm::class)->handle($this, $this->site, $this->bindingForm);
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site = $this->site->fresh() ?? $this->site;
        $this->dispatch('close-modal', 'site-binding-modal');
        $this->toastSuccess(__('Provisioning a dedicated database server — this can take several minutes.'));
    }

    public function provisionDedicatedDockerDatabaseVm(): void
    {
        Gate::authorize('update', $this->site);
        $this->releaseFailedBindingBeforeReplace();

        try {
            app(CreateDedicatedDockerDatabaseVm::class)->handle($this, $this->site, $this->bindingForm);
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site = $this->site->fresh() ?? $this->site;
        $this->dispatch('close-modal', 'site-binding-modal');
        $this->toastSuccess(__('Provisioning a dedicated Docker database server — this can take several minutes.'));
    }

    public function provisionDedicatedRedisVm(): void
    {
        Gate::authorize('update', $this->site);
        $this->releaseFailedBindingBeforeReplace();

        try {
            app(CreateDedicatedRedisVm::class)->handle($this, $this->site, $this->bindingForm);
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site = $this->site->fresh() ?? $this->site;
        $this->dispatch('close-modal', 'site-binding-modal');
        $this->toastSuccess(__('Provisioning a dedicated Redis server — this can take several minutes.'));
    }

    /**
     * Confirm before installing Docker Engine from the binding placement picker.
     */
    public function confirmInstallDockerOnServer(): void
    {
        Gate::authorize('update', $this->site);

        $def = config('server_manage.service_actions.install_docker', []);
        $this->openConfirmActionModal(
            'installDockerOnServer',
            [],
            (string) ($def['label'] ?? __('Install Docker Engine')),
            (string) ($def['confirm'] ?? __('Install Docker Engine on this server?')),
            __('Install Docker'),
            false,
        );
    }

    /**
     * Queue the same Manage → Tools Docker install without leaving the binding modal.
     */
    public function installDockerOnServer(): void
    {
        Gate::authorize('update', $this->site);

        $user = auth()->user();
        if ($user !== null && ($user->currentOrganization()?->userIsDeployer($user) ?? false)) {
            $this->toastError(__('Deployers cannot install packages on servers.'));

            return;
        }

        $server = $this->site->server;
        if (! $server instanceof Server) {
            return;
        }

        $server->refresh();
        $this->syncBindingServer($server);

        if ($server->dockerEnginePresent()) {
            $this->bindingForm['placement'] = 'docker';
            $this->toastSuccess(__('Docker Engine is already installed on this server.'));

            return;
        }

        if ($this->dockerInstallIsInFlight($server)) {
            $this->toastSuccess(__('Docker Engine is already installing on this server.'));

            return;
        }

        if (! $server->isReady() || ! filled($server->ip_address) || ! filled($server->ssh_private_key)) {
            $this->toastError(__('Provisioning and SSH must be ready before installing Docker.'));

            return;
        }

        $def = config('server_manage.service_actions.install_docker');
        if (! is_array($def) || empty($def['script'])) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $label = (string) ($def['label'] ?? __('Install Docker Engine'));
        $run = $this->seedBindingConsoleAction('install_docker', __('Installing Docker Engine'));

        app(ServerManageScriptQueuer::class)->queue(
            $server,
            'manage-action:install_docker',
            (string) $def['script'],
            isset($def['timeout']) ? (int) $def['timeout'] : 600,
            $label.' '.__('finished.'),
            $label,
            $user?->id !== null ? (string) $user->id : null,
            (string) $run->id,
        );

        $this->dockerInstallRunId = (string) $run->id;

        if (method_exists($this, 'watchConsoleAction')) {
            $this->watchConsoleAction(
                $run,
                __('Docker Engine is installed — you can select this option now.'),
                __('Docker Engine install failed.'),
            );
        }

        $this->toastSuccess(__('Installing Docker Engine on this server — this page stays open until it finishes.'));
    }

    /**
     * Poll target while Docker Engine is installing from the placement picker.
     */
    public function syncDockerInstallProgress(): void
    {
        if (method_exists($this, 'resolveWatchedConsoleAction')) {
            $this->resolveWatchedConsoleAction();
        }

        $server = $this->site->server;
        if (! $server instanceof Server) {
            return;
        }

        $server->refresh();
        $this->syncBindingServer($server);

        if ($this->dockerInstallRunId === null && ! $this->dockerInstallIsInFlight($server)) {
            return;
        }

        $latest = ServerManageAction::query()
            ->where('server_id', $server->id)
            ->where('task_name', 'manage-action:install_docker')
            ->latest('created_at')
            ->first();

        if ($latest?->status === ServerManageAction::STATUS_FINISHED) {
            if (! $server->dockerEnginePresent()) {
                $this->markDockerEnginePresent($server);
            }
            $this->bindingForm['placement'] = 'docker';
            $this->dockerInstallRunId = null;

            return;
        }

        if ($latest?->status === ServerManageAction::STATUS_FAILED) {
            $this->dockerInstallRunId = null;
        }
    }

    /**
     * Placement options for the "Provision new database" modal: always
     * on-box, plus a co-located managed cluster when the server's provider
     * offers one (DigitalOcean today). Each option carries the engines it
     * supports so the modal can filter as the operator picks an engine, and
     * an `available` flag (false when the managed backend exists but no
     * provider credential is connected). Region and cost stay off the cards
     * until the operator picks that placement.
     *
     * @return list<array{key: string, label: string, sublabel: string, available: bool, note: ?string, engines: list<string>, installing?: bool, install_action?: bool, serverless?: bool, regions?: list<array{value: string, label: string}>, account_label?: ?string, account_required?: bool, estimated_monthly_cost?: int|null}>
     */
    public function databasePlacements(): array
    {
        $options = [[
            'key' => 'on_box',
            'label' => __('On this server'),
            'sublabel' => __('Free · shares the box'),
            'available' => true,
            'note' => null,
            'engines' => ['mysql', 'postgres', 'clickhouse', 'sqlite'],
        ]];

        $server = $this->site->server;
        if ($server === null) {
            return $options;
        }

        $dockerPresent = $server->dockerEnginePresent();
        $dockerInstalling = ! $dockerPresent && $this->dockerInstallIsInFlight($server);

        $options[] = [
            'key' => 'docker',
            'label' => __('Docker container on this server'),
            'sublabel' => __('Isolated · uses Docker Engine'),
            'available' => $dockerPresent,
            'note' => $dockerPresent
                ? null
                : ($dockerInstalling
                    ? __('Installing Docker Engine…')
                    : __('Docker Engine is not installed on this server yet.')),
            'engines' => DockerDatabase::supportedEngines(),
            'installing' => $dockerInstalling,
            'install_action' => ! $dockerPresent,
        ];

        // Co-located managed cluster — only when the server's provider offers
        // one (DO / Vultr). Hetzner & co. skip this card but still get the
        // dedicated-VM and serverless options below.
        $backend = app(DatabaseRouter::class)->colocatedBackendFor($server);
        if ($backend !== null) {
            $region = $backend->regionForServer($server);
            $cost = $backend->estimatedMonthlyCost((string) ($this->bindingForm['size'] ?? 'small'));
            $hasCredential = $server->provider_credential_id !== null
                || ProviderCredential::query()
                    ->where('organization_id', $this->site->organization_id)
                    ->where('provider', $server->provider->value)
                    ->exists();

            $isCache = $this->bindingModalType === 'redis'
                || in_array((string) ($this->bindingForm['engine'] ?? ''), ['redis', 'valkey'], true);

            $options[] = [
                'key' => 'managed',
                'label' => $server->provider->label().' '.($isCache ? __('Managed Valkey') : __('Managed')),
                'sublabel' => $isCache
                    ? __('Isolated · billed by :provider · Redis-compatible', ['provider' => $server->provider->label()])
                    : __('Isolated · billed by :provider', ['provider' => $server->provider->label()]),
                'available' => $hasCredential && $region !== null,
                'note' => $hasCredential ? null : __('Connect a :provider credential first', ['provider' => $server->provider->label()]),
                'engines' => $backend->supportedEngines(),
                'estimated_monthly_cost' => $cost,
            ];
        }

        // Dedicated DB VM: a brand-new server on the customer's provider whose
        // only job is this database. Size / catalog errors belong in the picker
        // after the card is chosen — not on every unselected row.
        if (DedicatedDatabaseVm::eligible($server)) {
            $options[] = [
                'key' => 'dedicated_vm',
                'label' => __('Dedicated database server'),
                'sublabel' => __('New :provider VM · isolated host', ['provider' => $server->provider->label()]),
                'available' => true,
                'note' => null,
                'engines' => DedicatedDatabaseVm::supportedEngines(),
            ];
            $options[] = [
                'key' => 'docker_vm',
                'label' => __('Dedicated Docker database server'),
                'sublabel' => __('New :provider VM · Docker container', ['provider' => $server->provider->label()]),
                'available' => true,
                'note' => null,
                'engines' => DockerDatabase::supportedEngines(),
            ];
            $options[] = [
                'key' => 'cache_vm',
                'label' => __('Dedicated Redis server'),
                'sublabel' => __('New :provider VM · Redis only', ['provider' => $server->provider->label()]),
                'available' => true,
                'note' => null,
                'engines' => ['redis'],
            ];
        }

        // BYO serverless vendors (Neon / Supabase / Upstash …): region-agnostic.
        // Flag-off vendors stay visible as Coming soon, not hidden.
        foreach (ServerlessDatabaseVendors::all() as $vendor) {
            $enabled = ServerlessDatabaseVendors::isEnabled($vendor['key']);
            $options[] = [
                'key' => $vendor['key'],
                'label' => $vendor['label'],
                'sublabel' => __('serverless · bring your own account'),
                'available' => $enabled,
                'note' => $enabled ? null : __('Coming soon'),
                'engines' => $vendor['engines'],
                'serverless' => true,
                'regions' => $vendor['regions'],
                'account_label' => $vendor['account_label'],
                'account_required' => $vendor['account_required'],
            ];
        }

        return $options;
    }

    /**
     * Pre-fill {@see $bindingForm} from an existing binding row for editing.
     * Multi-instance types (storage, database) support several rows per site, so
     * editing one re-seeds its non-secret fields; other types round-trip via
     * their own default-form prefill.
     */
    private function seedBindingFormForEdit(string $type, string $bindingId): void
    {
        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->where('type', $type)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof SiteBinding) {
            return;
        }

        $this->bindingModalBindingId = (string) $binding->id;
        $config = (array) $binding->config;
        $cluster = $binding->target_type === 'cloud_database' && filled($binding->target_id)
            ? CloudDatabase::query()->find($binding->target_id)
            : null;
        $size = $cluster instanceof CloudDatabase
            ? $cluster->backendSizeSlug()
            : (string) ($config['size'] ?? $config['vm_size'] ?? '');
        $resizingTo = (string) ($config['resizing_to'] ?? '');

        $this->bindingEdit = [
            'id' => (string) $binding->id,
            'status' => (string) $binding->status,
            'placement_label' => $this->bindingPlacementLabel($config),
            'provisioned' => $binding->wasProvisionedByDply(),
            'can_retry' => $this->canRetryBindingProvision($binding),
            'can_delete' => $binding->canOfferDeleteOnDetach(),
            'can_test' => $binding->status === SiteBinding::STATUS_CONFIGURED,
            'can_resize' => $this->canResizeManagedBinding($binding, $cluster),
            'resizing_to' => $resizingTo,
            'region' => (string) ($config['region'] ?? ($cluster?->region ?? '')),
            'size' => $size,
            'service' => (string) ($config['service'] ?? $binding->name ?? ''),
        ];
        $this->bindingForm['use_for_drivers'] = ! empty($config['use_for_drivers']);

        // Multi-instance types (database, redis, …; not storage) re-select the
        // underlying target + the connection name so the form opens on the exact
        // instance being edited (secrets are never echoed).
        if (SiteBinding::isMultiInstance($type) && $type !== 'storage') {
            $config = (array) $binding->config;
            $this->bindingForm['target_id'] = (string) ($binding->target_id ?? '');
            $this->bindingForm['connection'] = (string) ($config['connection'] ?? '');
            // Provider-keyed types (ai/oauth/sms/captcha) open on the provider
            // being edited; secrets aren't echoed, so the operator re-supplies
            // the key (or reuses a saved credential).
            if (($config['provider'] ?? '') !== '') {
                $this->bindingForm['provider'] = (string) $config['provider'];
            }
            if (($config['redirect'] ?? '') !== '') {
                $this->bindingForm['redirect'] = (string) $config['redirect'];
            }
            // Mail's per-site from-address/name aren't secret, so re-seed them.
            foreach (['from_address', 'from_name'] as $k) {
                if (($config[$k] ?? '') !== '') {
                    $this->bindingForm[$k] = (string) $config[$k];
                }
            }
        }

        if (in_array($type, ['database', 'redis'], true)) {
            $config = (array) $binding->config;
            if (($config['placement'] ?? '') !== '') {
                $this->bindingForm['placement'] = (string) $config['placement'];
            }
            $clusterName = $this->bindingClusterName($binding, $config);
            if ($clusterName !== '') {
                $this->bindingForm['name'] = $clusterName;
            }
            foreach (['size', 'region', 'engine', 'vm_size'] as $key) {
                if (($config[$key] ?? '') !== '') {
                    $this->bindingForm[$key] = (string) $config[$key];
                }
            }
            if ($this->isManagedBindingPlacement($config)) {
                $engine = (string) ($this->bindingForm['engine'] ?? ($type === 'redis' ? 'redis' : 'postgres'));
                $this->coerceManagedDatabaseRegion(
                    $engine,
                    ProviderManagedDatabaseRegion::rejectedFromError(
                        $binding->last_error ?? (isset($config['last_error']) ? (string) $config['last_error'] : null),
                    ),
                );
                $this->bindingForm['size'] = CloudDatabase::resolveSizeSlug(
                    $resizingTo !== '' ? $resizingTo : (string) ($this->bindingForm['size'] ?? $size ?: 'small'),
                );
            }
        }

        if ($type === 'database') {
            $config = (array) $binding->config;
            foreach (['read_replica_type', 'read_replica_id', 'read_replica_host', 'read_replica_port', 'read_replica_username',
                'db_prefix', 'db_charset', 'db_collation', 'db_strict', 'db_engine', 'db_socket', 'db_schema', 'db_sslmode', 'db_timezone'] as $k) {
                if (($config[$k] ?? '') !== '') {
                    $this->bindingForm[$k] = (string) $config[$k];
                }
            }
        }

        if ($type === 'storage') {
            $config = (array) $binding->config;
            // Legacy rows stored the bucket in `name` with no `config['disk']`;
            // treat those as the primary `s3` disk.
            $this->bindingForm['disk'] = (string) ($config['disk'] ?? 's3');
            $this->bindingForm['bucket'] = (string) ($config['bucket'] ?? '');
            if (($config['provider'] ?? '') !== '') {
                $this->bindingForm['provider'] = (string) $config['provider'];
            }
            if (($config['region'] ?? '') !== '') {
                $this->bindingForm['region'] = (string) $config['region'];
            }
        }
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
        $this->bindingForm = $this->defaultBindingForm($this->bindingModalType, $mode === 'edit' ? 'attach' : $mode);
        $this->bindingTargets = app(SiteBindingManager::class)->attachableTargets($this->site, $this->bindingModalType);

        // Toggling into "Provision new" must load the dedicated-VM size
        // catalog so the picker is ready after they choose that card.
        $this->dedicatedVmSizes = [];
        $this->dedicatedVmSizeError = null;
        if (in_array($this->bindingModalType, ['database', 'redis'], true) && $mode === 'provision') {
            $this->loadDedicatedVmSizes();
        }

        $this->resetErrorBag();
    }

    private function saveEditedBinding(SiteBindingManager $manager): void
    {
        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($this->bindingModalBindingId)
            ->first();

        if (! $binding instanceof SiteBinding) {
            return;
        }

        $params = $this->bindingForm + ['binding_id' => (string) $binding->id];

        try {
            if ($binding->wasProvisionedByDply()) {
                $manager->updateEditedBinding($binding, $params);
            } else {
                $manager->attachExisting($this->site, $binding->type, $params);
            }
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site = $this->site->fresh() ?? $this->site;
        $this->dispatch('close-modal', 'site-binding-modal');
        $this->toastSuccess(__('Binding updated.'));
    }

    public function openResizeManagedBindingConfirmModal(): void
    {
        Gate::authorize('update', $this->site);

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($this->bindingModalBindingId)
            ->first();

        if (! $binding instanceof SiteBinding) {
            return;
        }

        $cluster = $binding->target_type === 'cloud_database' && filled($binding->target_id)
            ? CloudDatabase::query()->find($binding->target_id)
            : null;

        if (! $this->canResizeManagedBinding($binding, $cluster) || ! $cluster instanceof CloudDatabase) {
            $this->toastError(__('This cluster cannot be resized from here.'));

            return;
        }

        $current = $cluster->backendSizeSlug();
        $size = CloudDatabase::resolveSizeSlug((string) ($this->bindingForm['size'] ?? ''));

        if ($size === '' || $size === $current) {
            $this->toastError(__('Pick a different plan first.'));

            return;
        }

        $upsizing = ManagedDatabaseSizeCatalog::rank($size) > ManagedDatabaseSizeCatalog::rank($current);
        $this->dispatch('close-modal', 'site-binding-modal');
        $this->openConfirmActionModal(
            'resizeManagedBinding',
            [(string) $binding->id, $size],
            $upsizing
                ? __('Upsize this cluster?')
                : __('Downsize this cluster?'),
            $upsizing
                ? __('DigitalOcean will apply the larger plan on this same cluster. The host usually stays the same.')
                : __('DigitalOcean will apply the smaller plan on this same cluster. Downsizing can fail if the dataset does not fit.'),
            $upsizing ? __('Upsize') : __('Downsize'),
            ! $upsizing,
            [
                ['label' => __('Current'), 'value' => ManagedDatabaseSizeCatalog::label($current)],
                ['label' => __('New plan'), 'value' => ManagedDatabaseSizeCatalog::label($size)],
            ],
            null,
            '',
            false,
            __('The cluster stays attached. Apps keep using the same REDIS_* / DB_* host while it resizes.'),
        );
    }

    public function resizeManagedBinding(string $bindingId, string $size): void
    {
        Gate::authorize('update', $this->site);

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof SiteBinding) {
            return;
        }

        $cluster = $binding->target_type === 'cloud_database' && filled($binding->target_id)
            ? CloudDatabase::query()->find($binding->target_id)
            : null;

        if (! $this->canResizeManagedBinding($binding, $cluster) || ! $cluster instanceof CloudDatabase) {
            $this->toastError(__('This cluster cannot be resized from here.'));

            return;
        }

        $size = CloudDatabase::resolveSizeSlug($size);
        $current = $cluster->backendSizeSlug();
        if ($size === '' || $size === $current) {
            $this->toastError(__('Pick a different plan first.'));

            return;
        }

        $config = is_array($binding->config) ? $binding->config : [];
        $config['resizing_to'] = $size;
        $binding->forceFill([
            'config' => $config,
            'last_error' => null,
        ])->save();

        ResizeManagedDatabaseJob::dispatch((string) $cluster->id, (string) $binding->id, $size);

        $this->site = $this->site->fresh() ?? $this->site;
        $this->bindingModalMode = '';
        $this->bindingModalBindingId = null;
        $this->bindingEdit = null;
        $this->toastSuccess(__('Resize queued. Watch the console for DigitalOcean progress.'));
    }

    public function saveBinding(SiteBindingManager $manager): void
    {
        Gate::authorize('update', $this->site);

        if ($this->bindingModalMode === 'edit') {
            $this->saveEditedBinding($manager);

            return;
        }

        // Placement decides which provisioner runs, and the two dedicated cards
        // differ only by one word ("Dedicated database server" vs "Dedicated
        // Docker database server") while routing to completely different
        // infrastructure. Verify the submitted placement is real and supports
        // the chosen engine instead of falling through to a different one.
        if (in_array($this->bindingModalType, ['database', 'redis'], true) && $this->bindingModalMode === 'provision') {
            $placement = (string) ($this->bindingForm['placement'] ?? '');
            $engine = strtolower(trim((string) ($this->bindingForm['engine'] ?? ($this->bindingModalType === 'redis' ? 'redis' : ''))));
            if ($this->bindingModalType === 'database' && in_array($engine, ['redis', 'valkey'], true)) {
                $this->toastError(__('Redis belongs on the Redis / Valkey resource, not Database.'));

                return;
            }
            $match = collect($this->databasePlacements())->firstWhere('key', $placement);

            if ($placement === '') {
                $this->toastError(__('Pick where it should live first.'));

                return;
            }

            if ($match === null) {
                $this->toastError(__('That database placement is no longer available. Pick one again.'));

                return;
            }

            if ($engine !== '' && ! in_array($engine, $match['engines'], true)) {
                $this->toastError(__(':placement does not support :engine. Pick a different placement or engine.', [
                    'placement' => $match['label'],
                    'engine' => $engine,
                ]));

                return;
            }
        }

        // The dedicated-DB-VM placement provisions a whole new server, which
        // means driving the customer-connected create pipeline (a Livewire Form
        // object) — handled in the component layer, not the binding manager.
        if ($this->bindingModalType === 'database'
            && $this->bindingModalMode === 'provision'
            && ($this->bindingForm['placement'] ?? '') === 'dedicated_vm') {
            $this->provisionDedicatedDatabaseVm();

            return;
        }

        if ($this->bindingModalType === 'database'
            && $this->bindingModalMode === 'provision'
            && ($this->bindingForm['placement'] ?? '') === 'docker_vm') {
            $this->provisionDedicatedDockerDatabaseVm();

            return;
        }

        if (in_array($this->bindingModalType, ['database', 'redis'], true)
            && $this->bindingModalMode === 'provision'
            && ($this->bindingForm['placement'] ?? '') === 'cache_vm') {
            $this->provisionDedicatedRedisVm();

            return;
        }

        // Auto-provision Redis on connect: when there's no Redis to attach AND
        // none is installed on the box, kick the install right from the connect
        // action instead of dead-ending on "nothing reachable" (the operator no
        // longer has to spot and click the separate Install Redis button). Once
        // it's running, reconnect attaches it. If Redis IS installed but just
        // unreachable, fall through so attach surfaces the precise error.
        if ($this->bindingModalType === 'redis'
            && $this->bindingModalMode !== 'provision'
            && $this->maybeAutoInstallRedis($manager)) {
            return;
        }

        // Carry which row (if any) we're editing so multi-instance types (storage)
        // can update that row instead of rejecting it as a duplicate disk name.
        $params = $this->bindingForm + ['binding_id' => $this->bindingModalBindingId];

        // Error tracking is a single-mode ("Configure") form, but Lookout offers
        // an in-form toggle between minting a project (provision) and pasting a
        // DSN (attach). Route on that sub-mode rather than the modal's mode.
        $useProvision = $this->bindingModalMode === 'provision';
        if ($this->bindingModalType === 'error_tracking'
            && ($this->bindingForm['provider'] ?? '') === 'lookout') {
            $useProvision = (($this->bindingForm['lookout_mode'] ?? 'provision') === 'provision');
        }

        try {
            $binding = $useProvision
                ? $manager->provisionNew($this->site, $this->bindingModalType, $params)
                : $manager->attachExisting($this->site, $this->bindingModalType, $params);
        } catch (Throwable $e) {
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

        // A freshly provisioned database is still being CREATEd on the host by a
        // queued job, so its endpoint isn't up yet — skip the connectivity probe
        // now (it would race and report "unreachable"); it gets validated once
        // the provision job flips the binding to configured.
        if ($binding->status !== SiteBinding::STATUS_PROVISIONING) {
            $this->validateBindingConnectivity($binding);
        }

        // Connecting Redis must "just work": the app now dials phpredis (and may
        // use redis for cache/sessions/queue), so the box needs the PHP redis
        // client extension or it 500s at runtime with `Class "Redis" not found`.
        // That guarantee now lives in the deploy resource-verify gate
        // ({@see \App\Services\Sites\DeployResourceVerifier}) — it checks and
        // idempotently installs the extension pre-cutover whenever a redis binding
        // is present, so it runs once per deploy alongside the reachability probes
        // instead of as a standalone console-action banner that lingered on the
        // deploy hub after every attach. The new env (REDIS_CLIENT=phpredis) only
        // goes live on that same deploy/restart anyway, so the timing lines up.

        // Connecting Lookout must "just work": the injected LOOKOUT_DSN only does
        // anything if the app requires the lookout/tracing SDK. dply can't edit
        // the app's composer.json, so add the dependency on the box now (no-op
        // when already present) — the next deploy's composer install picks it up.
        if ($binding->type === 'error_tracking'
            && (((array) $binding->config)['provider'] ?? '') === 'lookout') {
            $this->ensureComposerPackage($binding, 'lookout/tracing');
        }

        // Connecting a mail transport must "just work" too: API-based providers
        // (Cloudflare, Mailgun, Postmark, Resend, SendGrid, SES) ship their
        // Symfony transport — and its HTTP client — as separate Composer packages.
        // Without them the app (and a test-send) dies with `Class "…HttpClient"
        // not found`. Mirror the Lookout path — add each leg's package on the box
        // now (no-op when present) — so the binding sends instead of fataling.
        if ($binding->type === 'mail') {
            $mailConfig = (array) $binding->config;
            $mailProviders = array_merge(
                [(string) ($mailConfig['provider'] ?? '')],
                array_map(strval(...), (array) ($mailConfig['legs'] ?? [])),
            );
            $packages = [];
            foreach ($mailProviders as $mailProvider) {
                $package = SiteBindingManager::MAIL_TRANSPORT_PACKAGES[strtolower(trim($mailProvider))] ?? null;
                if ($package !== null) {
                    $packages[$package] = true;
                }
            }
            foreach (array_keys($packages) as $package) {
                $this->ensureComposerPackage($binding, $package);
            }
        }
    }

    /**
     * If connecting Redis with nothing to attach and nothing installed, kick the
     * install automatically and report it. Returns true when it handled the save
     * (caller should stop); false when a Redis is already reachable/installed so
     * the normal attach path should run.
     */
    private function maybeAutoInstallRedis(SiteBindingManager $manager): bool
    {
        // An explicit target pick means the operator chose a reachable service.
        if (trim((string) ($this->bindingForm['target_id'] ?? '')) !== '') {
            return false;
        }

        // Something reachable to attach → let the normal path handle it.
        if ($manager->attachableTargets($this->site, 'redis') !== []) {
            return false;
        }

        // A Redis-family service exists on the box but isn't reachable (e.g.
        // still installing, or a peer needing remote access) → don't double
        // install; let attach throw its precise, actionable error.
        $installed = ServerCacheService::query()
            ->where('server_id', $this->site->server_id)
            ->whereIn('engine', ServerCacheService::FAMILY_REDIS_ENGINES)
            ->exists();
        if ($installed) {
            return false;
        }

        // Nothing reachable, nothing installed → auto-provision. installCacheOnServer
        // creates the pending service, dispatches the install job, closes the
        // modal, and toasts "Installing…".
        $this->installCacheOnServer('redis');

        return true;
    }

    /**
     * Resolve the Lookout organizations a pasted API token can create projects
     * under, so the provision form can show a picker instead of a raw ULID.
     * Best-effort: a bad token or older Lookout just leaves the list empty and
     * the operator types the org id by hand. Preselects the only org when there
     * is exactly one.
     */
    public function loadLookoutOrganizations(): void
    {
        Gate::authorize('update', $this->site);

        $token = trim((string) ($this->bindingForm['lookout_token'] ?? ''));
        if ($token === '') {
            $this->lookoutOrganizations = [];
            $this->toastError(__('Paste your Lookout API token first.'));

            return;
        }

        $orgs = app(LookoutProvisioner::class)->organizations($token);
        $this->lookoutOrganizations = $orgs;

        if ($orgs === []) {
            $this->toastError(__('Could not load organizations — check the token, or enter the organization ID manually.'));

            return;
        }

        if (count($orgs) === 1) {
            $this->bindingForm['lookout_org'] = $orgs[0]['id'];
        }
    }

    /**
     * Detach a binding. When $deleteResource is true AND dply provisioned the
     * underlying resource, its infra is torn down too (managed cluster deleted,
     * dedicated DB VM destroyed, on-box database dropped, provisioned bucket
     * emptied). BYO/attached-existing resources the customer owns are never
     * deleted — the flag is a no-op for them (see SiteBinding::provisionedResource()).
     * The delete flag is supplied by the confirm modal's opt-in toggle, so it
     * arrives as the trailing argument (no DI-typed parameter after it).
     */
    public function openDetachBindingConfirmModal(string $bindingId, ?string $label = null, bool $preferDelete = false): void
    {
        Gate::authorize('update', $this->site);

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof SiteBinding) {
            return;
        }

        // Close the status/info modal from PHP. Alpine `$dispatch('close')` on
        // the same click hides every `x-modal` (generic `close`) and can abort
        // the Livewire request when the button is unmounted — the confirm then
        // never appears, so a stuck provisioning cluster cannot be detached.
        $this->bindingInfo = null;
        $this->dispatch('close-modal', 'binding-info-modal');

        $label = filled($label) ? $label : Str::headline($binding->type);
        $title = $preferDelete
            ? __('Detach and delete :label?', ['label' => $label])
            : __('Detach :label?', ['label' => $label]);
        $message = $preferDelete
            ? __('Remove this resource binding and delete the provisioned instance? Injected variables will no longer be applied at deploy. This cannot be undone.')
            : __('Remove this resource binding? Its injected variables will no longer be applied at deploy.');

        $toggleLabel = $binding->deleteOnDetachLabel();
        if ($toggleLabel !== null) {
            $this->openConfirmActionModal(
                'detachBinding',
                [$bindingId],
                $title,
                $message,
                __('Detach'),
                true,
                null,
                $toggleLabel,
                $binding->deleteOnDetachHint(),
                $preferDelete,
            );

            return;
        }

        $this->openConfirmActionModal(
            'detachBinding',
            [$bindingId],
            $title,
            $message,
            __('Detach'),
            true,
        );
    }

    public function openDetachAndDeleteBindingConfirmModal(string $bindingId, ?string $label = null): void
    {
        $this->openDetachBindingConfirmModal($bindingId, $label, true);
    }

    public function detachBinding(string $bindingId, bool $deleteResource = false): void
    {
        Gate::authorize('update', $this->site);

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof SiteBinding) {
            return;
        }

        $offeredDelete = $binding->canOfferDeleteOnDetach();

        try {
            app(SiteBindingManager::class)->detach($binding, $deleteResource);
        } catch (Throwable $e) {
            $this->toastError(__('Could not delete the resource: :error', ['error' => $e->getMessage()]));

            return;
        }

        $this->site = $this->site->fresh() ?? $this->site;
        $this->bindingInfo = null;
        $this->dispatch('close-modal', 'binding-info-modal');
        $this->toastSuccess($deleteResource && $offeredDelete
            ? __('Binding detached and the resource is being deleted.')
            : __('Binding detached.'));
    }

    /**
     * Open a read-only modal describing a binding's connection: the variables it
     * injects at deploy (secrets masked), its reachability/status, and where it
     * points. Pure inspection — no SSH, no mutation — so it's gated on `view`.
     */
    public function openBindingInfoModal(string $bindingId): void
    {
        Gate::authorize('view', $this->site);

        if (! $this->refreshBindingInfo($bindingId)) {
            return;
        }

        $regions = collect($this->bindingInfo['provision']['regions'] ?? [])->pluck('value')->filter()->values()->all();
        $region = ProviderManagedDatabaseRegion::resolve(
            $this->site->server?->provider?->value ?? '',
            (string) ($this->bindingForm['region'] ?? ''),
            (string) ($this->bindingInfo['provision']['region'] ?? ''),
            $regions,
        );
        if ($region !== null) {
            $this->bindingForm['region'] = $region;
        }

        $this->dispatch('open-modal', 'binding-info-modal');

        if (! empty($this->bindingInfo['provision']['auth_failure'])) {
            $this->dispatch(
                'open-add-provider-credential-modal',
                provider: (string) ($this->bindingInfo['provision']['auth_provider'] ?? ''),
            );
        }
    }

    public function openProviderCredentialModal(?string $provider = null): void
    {
        $this->dispatch('open-add-provider-credential-modal', provider: $provider);
    }

    #[On('provider-credential-created')]
    public function afterProviderCredentialCreatedForBindings(string $provider = '', mixed $credentialId = null): void
    {
        ManagedDatabaseCatalogFailure::clear();

        $info = $this->bindingInfo;
        if (! is_array($info) || empty($info['provision']['auth_failure']) || ! filled($info['id'] ?? null)) {
            return;
        }

        $expected = (string) ($info['provision']['auth_provider'] ?? '');
        if ($expected !== '' && $provider !== '' && $expected !== $provider) {
            return;
        }

        $this->retryFailedBindingProvision((string) $info['id']);
    }

    public function afterConsoleActionCancelled(ConsoleAction $run): void
    {
        Gate::authorize('update', $this->site);

        if (! in_array($run->kind, [
            ManagedDatabaseProvisionConsole::KIND,
            ManagedDatabaseProvisionConsole::KIND_RESIZE,
        ], true)) {
            return;
        }

        $message = __('Stopped.');
        $bindings = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->where('status', SiteBinding::STATUS_PROVISIONING)
            ->get();

        foreach ($bindings as $binding) {
            $config = is_array($binding->config) ? $binding->config : [];
            if ((string) ($config['console_run_id'] ?? '') !== (string) $run->id) {
                continue;
            }

            $binding->forceFill([
                'status' => SiteBinding::STATUS_ERROR,
                'last_error' => $message,
            ])->save();

            if ($binding->target_type === 'cloud_database' && filled($binding->target_id)) {
                $database = CloudDatabase::query()->find($binding->target_id);
                if ($database instanceof CloudDatabase && $database->status === CloudDatabase::STATUS_PROVISIONING) {
                    $meta = is_array($database->meta) ? $database->meta : [];
                    $meta['error'] = $message;
                    $meta['error_at'] = now()->toIso8601String();
                    $database->forceFill([
                        'status' => CloudDatabase::STATUS_FAILED,
                        'meta' => $meta,
                    ])->save();
                }
            }
        }

        ManagedDatabaseProvisionConsole::fail($run, $message);
        $this->toastSuccess(__('Stopped provisioning.'));

        if (is_array($this->bindingInfo) && filled($this->bindingInfo['id'] ?? null)) {
            $this->refreshBindingInfo((string) $this->bindingInfo['id']);
        }
    }

    /**
     * Rebuild the read-only info payload for a binding. Used on open and again
     * while a provisioning modal stays open so the journey digest stays live.
     *
     * Named refresh* (not hydrate*) so Livewire does not treat it as a
     * property hydrator for {@see $bindingInfo}.
     */
    public function refreshBindingInfo(string $bindingId): bool
    {
        Gate::authorize('view', $this->site);

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof SiteBinding) {
            $this->bindingInfo = null;

            return false;
        }

        $config = is_array($binding->config) ? $binding->config : [];
        $env = $binding->injected_env;

        $vars = [];
        foreach ($env as $key => $value) {
            $sensitive = (bool) preg_match('/(PASSWORD|SECRET|TOKEN|KEY|DSN|URL|PASS)/i', (string) $key);
            $vars[] = [
                'key' => (string) $key,
                'value' => $sensitive ? $this->maskBindingSecret((string) $value) : (string) $value,
                'sensitive' => $sensitive,
            ];
        }

        $conn = is_array($config['connectivity'] ?? null) ? $config['connectivity'] : null;
        $provision = $this->bindingProvisionPayload($binding, $config);
        $consoleRun = $this->syncManagedProvisionConsole($binding);
        if ($consoleRun instanceof ConsoleAction) {
            $provision['console_run_id'] = (string) $consoleRun->id;
        }

        $this->bindingInfo = [
            'id' => (string) $binding->id,
            'type' => (string) $binding->type,
            'name' => $binding->name,
            'status' => (string) $binding->status,
            'provider' => $config['provider'] ?? null,
            'placement' => $config['placement'] ?? null,
            'private_network' => ! empty($config['source_server_id']),
            'needs_remote_access' => ! empty($config['needs_remote_access']),
            'last_error' => $this->bindingResolvedError($binding, $config),
            'reachable' => is_array($conn) ? ($conn['ok'] ?? null) : null,
            'reachable_detail' => is_array($conn) ? ($conn['detail'] ?? null) : null,
            'checked_at' => is_array($conn) ? ($conn['checked_at'] ?? null) : null,
            'vars' => $vars,
            'can_delete_resource' => $binding->canOfferDeleteOnDetach(),
            'provision' => $provision,
        ];

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{
     *     active: bool,
     *     hint: string,
     *     placement_label: ?string,
     *     server_name: ?string,
     *     server_status: ?string,
     *     setup_status: ?string,
     *     server_url: ?string,
     *     journey_url: ?string,
     *     digest_phase: ?string,
     *     digest_step: ?string,
     *     digest_step_index: ?int,
     *     digest_step_total: ?int,
     *     digest_elapsed: ?string,
     *     digest_percent: ?int,
     *     failed: bool,
     *     error: ?string,
     *     can_retry: bool,
     *     can_fix_connectivity: bool,
     *     console_run_id: ?string
     * }
     */
    private function bindingProvisionPayload(SiteBinding $binding, array $config): array
    {
        $serverId = $binding->provisionServerId();
        $server = filled($serverId)
            ? Server::query()->whereKey($serverId)->first()
            : null;
        $digest = $server instanceof Server ? ProvisioningDigest::forServer($server) : null;
        $percent = ($digest !== null && $digest->stepIndex && $digest->stepTotal)
            ? max(0, min(100, (int) round(100 * $digest->stepIndex / $digest->stepTotal)))
            : null;
        $conn = is_array($config['connectivity'] ?? null) ? $config['connectivity'] : null;
        $error = $binding->displayError($server instanceof Server ? $server : null);
        $authProvider = $this->bindingAuthProvider($binding, $config);
        $authFailure = $binding->isErrored() && ProviderAuthFailure::detected($error);

        return [
            'active' => $binding->isProvisioning(),
            'hint' => $this->bindingProvisionHint($binding, $config),
            'placement_label' => $this->bindingPlacementLabel($config),
            'server_name' => $server instanceof Server ? $server->name : null,
            'server_status' => $server instanceof Server ? $server->status : null,
            'setup_status' => $server instanceof Server ? $server->setup_status : null,
            'server_url' => $server instanceof Server ? route('servers.show', $server) : null,
            'journey_url' => $server instanceof Server ? route('servers.journey', $server) : null,
            'digest_phase' => $digest?->phaseLabel,
            'digest_step' => $digest?->stepLabel,
            'digest_step_index' => $digest?->stepIndex,
            'digest_step_total' => $digest?->stepTotal,
            'digest_elapsed' => $digest?->elapsedHuman(),
            'digest_percent' => $percent,
            'failed' => $binding->isErrored(),
            'error' => $authFailure ? ProviderAuthFailure::message($authProvider) : $error,
            'error_detail' => $authFailure ? $error : null,
            'auth_failure' => $authFailure,
            'auth_provider' => $authProvider,
            'auth_provider_label' => ProviderAuthFailure::providerLabel($authProvider),
            'auth_title' => $authFailure ? ProviderAuthFailure::title($authProvider) : null,
            'can_retry' => $this->canRetryBindingProvision($binding) && ! $authFailure,
            'can_change_placement' => $this->canRetryBindingProvision($binding)
                && in_array($binding->type, ['database', 'redis'], true),
            'can_pick_region' => $this->canRetryBindingProvision($binding)
                && ! $authFailure
                && $this->isManagedBindingPlacement($config)
                && $this->managedDatabaseRegions($this->managedDatabaseEngineFor($binding, $config)) !== [],
            'regions' => $this->isManagedBindingPlacement($config)
                ? $this->managedDatabaseRegions(
                    $this->managedDatabaseEngineFor($binding, $config),
                    ProviderManagedDatabaseRegion::rejectedFromError($this->bindingResolvedError($binding, $config)),
                )
                : [],
            'region' => (string) ($config['region'] ?? ''),
            'can_fix_connectivity' => is_array($conn) && ($conn['ok'] ?? true) === false,
            'console_run_id' => isset($config['console_run_id']) ? (string) $config['console_run_id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function bindingAuthProvider(SiteBinding $binding, array $config = []): string
    {
        $fromConfig = strtolower(trim((string) ($config['provider'] ?? '')));
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        $serverProvider = $this->site->server?->provider;
        if ($serverProvider instanceof ServerProvider) {
            return $serverProvider->value;
        }

        return 'unknown';
    }

    private function syncManagedProvisionConsole(SiteBinding $binding): ?ConsoleAction
    {
        $config = is_array($binding->config) ? $binding->config : [];
        if (! $this->isManagedBindingPlacement($config) || $binding->target_type !== 'cloud_database') {
            return null;
        }

        $database = CloudDatabase::query()->find($binding->target_id);
        if (! $database instanceof CloudDatabase) {
            return null;
        }

        $run = ManagedDatabaseProvisionConsole::ensure($this->site, $binding, $database);

        if (! $binding->isProvisioning()) {
            return $run;
        }

        $backendId = trim((string) $database->backend_id);
        if ($backendId === '') {
            ManagedDatabaseProvisionConsole::noteIfNew(
                $run,
                'digitalocean',
                __('Waiting for DigitalOcean to accept the create.'),
            );

            return $run;
        }

        try {
            $database->loadMissing('providerCredential');
            $credential = $database->providerCredential;
            if ($credential === null) {
                return $run;
            }

            $cluster = (new DigitalOceanService($credential))->getDatabaseCluster($backendId);
            $status = (string) ($cluster['status'] ?? '');
            $elapsed = max(1, (int) ($database->created_at?->diffInSeconds(now()) ?? 20));
            $attempt = max(1, (int) ceil($elapsed / 20));

            ManagedDatabaseProvisionConsole::poll($run, $database, $status, $attempt, 40);

            if ($status === 'online') {
                ManagedDatabaseProvisionConsole::noteIfNew(
                    $run,
                    'digitalocean',
                    __('Cluster is online. Wiring connection variables.'),
                    ConsoleAction::LEVEL_SUCCESS,
                );
            }
        } catch (Throwable $e) {
            ManagedDatabaseProvisionConsole::noteIfNew(
                $run,
                'digitalocean',
                __('Could not refresh cluster status: :error', ['error' => $e->getMessage()]),
                ConsoleAction::LEVEL_WARN,
            );
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function bindingResolvedError(SiteBinding $binding, array $config): ?string
    {
        $serverId = $binding->provisionServerId();
        $server = filled($serverId)
            ? Server::query()->whereKey($serverId)->first()
            : null;

        return $binding->displayError($server instanceof Server ? $server : null)
            ?? (isset($config['last_error']) ? (string) $config['last_error'] : null);
    }

    public function openFailedBindingRepair(string $bindingId): void
    {
        Gate::authorize('update', $this->site);

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof SiteBinding || ! $binding->isErrored()) {
            return;
        }

        $this->bindingInfo = null;
        $this->dispatch('close-modal', 'binding-info-modal');
        $this->openBindingModal($binding->type, 'provision', (string) $binding->id);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    /**
     * @param  list<string>  $rejected
     * @return list<array{value: string, label: string}>
     */
    public function managedDatabaseRegions(?string $engine = null, array $rejected = []): array
    {
        $server = $this->site->server;
        if ($server === null) {
            return [];
        }

        $engine = strtolower(trim((string) $engine));
        if ($engine === '') {
            $engine = $this->managedDatabaseEngine();
        }

        $rejected = array_values(array_unique(array_merge(
            $rejected,
            ProviderManagedDatabaseRegion::rejectedFromError(
                is_array($this->bindingInfo)
                    ? (string) ($this->bindingInfo['provision']['error'] ?? $this->bindingInfo['last_error'] ?? '')
                    : null,
            ),
        )));

        return ManagedDatabaseRegionCatalog::options($server, $engine, null, $rejected);
    }

    /**
     * @return list<array{value: string, label: string, group: string}>
     */
    public function managedDatabaseSizes(?string $engine = null): array
    {
        $server = $this->site->server;
        if ($server === null) {
            return [];
        }

        $engine = strtolower(trim((string) $engine));
        if ($engine === '') {
            $engine = $this->managedDatabaseEngine();
        }

        return ManagedDatabaseSizeCatalog::options($server, $engine);
    }

    public function managedDatabaseCatalogError(): ?string
    {
        return ManagedDatabaseCatalogFailure::operatorMessage();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function managedDatabaseEngineFor(SiteBinding $binding, array $config): string
    {
        $engine = strtolower(trim((string) ($config['engine'] ?? '')));
        if (in_array($engine, ['redis', 'valkey', 'postgres', 'postgresql', 'pg', 'mysql'], true)) {
            return $engine === 'postgresql' || $engine === 'pg' ? 'postgres' : $engine;
        }

        return $binding->type === 'redis' ? 'redis' : 'postgres';
    }

    private function managedDatabaseEngine(): string
    {
        $fromForm = strtolower(trim((string) ($this->bindingForm['engine'] ?? '')));
        if (in_array($fromForm, ['redis', 'valkey', 'postgres', 'mysql'], true)) {
            return $fromForm;
        }

        $type = (string) ($this->bindingInfo['type'] ?? $this->bindingModalType);

        return $type === 'redis' ? 'redis' : 'postgres';
    }

    /**
     * @param  list<string>  $rejected
     */
    private function coerceManagedDatabaseRegion(?string $engine = null, array $rejected = []): void
    {
        $options = $this->managedDatabaseRegions($engine, $rejected);
        $slugs = array_column($options, 'value');
        if ($slugs === []) {
            return;
        }

        $resolved = ProviderManagedDatabaseRegion::resolve(
            $this->site->server?->provider?->value ?? '',
            (string) ($this->bindingForm['region'] ?? ''),
            $this->site->server?->region,
            $slugs,
        );
        if ($resolved !== null) {
            $this->bindingForm['region'] = $resolved;
        }
    }

    public function retryFailedBindingProvision(string $bindingId): void
    {
        Gate::authorize('update', $this->site);

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof SiteBinding || ! $binding->isErrored()) {
            return;
        }

        if (! $this->canRetryBindingProvision($binding)) {
            $this->toastError(__('This resource cannot be retried from here — replace it or open the server journey.'));

            return;
        }

        $config = is_array($binding->config) ? $binding->config : [];
        $serverId = $binding->provisionServerId();
        $server = filled($serverId)
            ? Server::query()->whereKey($serverId)->first()
            : null;

        if ($this->isManagedBindingPlacement($config)) {
            $this->recreateFailedManagedCluster($binding, $config);

            return;
        }

        if ($server instanceof Server && filled($binding->target_id)) {
            if ($server->setup_status === Server::SETUP_STATUS_FAILED
                && RunSetupScriptJob::shouldDispatch($server)) {
                $meta = is_array($server->meta) ? $server->meta : [];
                unset($meta['provision_task_id'], $meta['provision_step_snapshots']);
                $server->forceFill([
                    'setup_status' => Server::SETUP_STATUS_PENDING,
                    'meta' => $meta,
                ])->save();
                WaitForServerSshReadyJob::dispatch($server->fresh() ?? $server);
            }

            $binding->forceFill([
                'status' => SiteBinding::STATUS_PROVISIONING,
                'last_error' => null,
            ])->save();

            $this->redispatchBindingProvisionWait($binding);
            $this->refreshBindingInfo((string) $binding->id);
            $this->toastSuccess(__('Retrying provision — watch this card for status.'));

            return;
        }

        $this->recreateFailedDedicatedVm($binding, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function recreateFailedDedicatedVm(SiteBinding $binding, array $config): void
    {
        $placement = (string) ($config['placement'] ?? '');
        $form = [
            'engine' => (string) ($config['engine'] ?? ($binding->type === 'redis' ? 'redis' : 'mysql')),
            'name' => (string) ($config['cluster_name'] ?? $config['database_name'] ?? ($binding->name ?: 'primary')),
            'placement' => $placement,
            'vm_size' => (string) ($config['vm_size'] ?? ''),
            'connection' => (string) ($config['connection'] ?? ''),
            'use_for_drivers' => (bool) ($config['use_for_drivers'] ?? false),
        ];

        $binding->delete();

        try {
            match ($placement) {
                'cache_vm' => app(CreateDedicatedRedisVm::class)->handle($this, $this->site, $form),
                'dedicated_vm' => app(CreateDedicatedDatabaseVm::class)->handle($this, $this->site, $form),
                'docker_vm' => app(CreateDedicatedDockerDatabaseVm::class)->handle($this, $this->site, $form),
                default => null,
            };
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site = $this->site->fresh() ?? $this->site;
        $this->bindingInfo = null;
        $this->toastSuccess(__('Retrying provision — watch this card for status.'));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function recreateFailedManagedCluster(SiteBinding $binding, array $config): void
    {
        $oldTargetId = (string) ($binding->target_id ?? '');
        $rejected = ProviderManagedDatabaseRegion::rejectedFromError(
            $this->bindingResolvedError($binding, $config),
        );
        if ($binding->target_type === 'cloud_database' && filled($binding->target_id)) {
            $failed = CloudDatabase::query()->find($binding->target_id);
            $rejected = array_values(array_unique(array_merge(
                $rejected,
                ProviderManagedDatabaseRegion::rejectedFromError(
                    is_array($failed?->meta) ? (string) ($failed->meta['error'] ?? '') : null,
                ),
            )));
        }
        $region = (string) ($this->bindingForm['region'] ?? $config['region'] ?? '');
        if (in_array(strtolower($region), $rejected, true)) {
            $region = '';
        }
        $form = [
            'engine' => (string) ($config['engine'] ?? ($binding->type === 'redis' ? CloudDatabase::ENGINE_REDIS : 'mysql')),
            'name' => $this->bindingClusterName($binding, $config),
            'placement' => (string) ($this->bindingForm['placement'] ?? $config['placement'] ?? 'managed'),
            'size' => (string) ($this->bindingForm['size'] ?? $config['size'] ?? 'small'),
            'region' => $region,
            'rejected_regions' => $rejected,
            'connection' => (string) ($config['connection'] ?? ''),
            'binding_id' => (string) $binding->id,
        ];

        try {
            $remade = app(SiteBindingManager::class)->provisionNew($this->site, $binding->type, $form);
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        if ($oldTargetId !== ''
            && $oldTargetId !== (string) $remade->target_id
            && $binding->target_type === 'cloud_database') {
            CloudDatabase::query()->whereKey($oldTargetId)->delete();
        }

        $this->site = $this->site->fresh() ?? $this->site;
        $this->refreshBindingInfo((string) $remade->id);
        $this->toastSuccess(__('Retrying provision — watch this card for status.'));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function bindingClusterName(SiteBinding $binding, array $config): string
    {
        foreach (['cluster_name', 'database_name'] as $key) {
            $name = trim((string) ($config[$key] ?? ''));
            if ($name !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $name) === 1) {
                return $name;
            }
        }

        $service = trim((string) ($config['service'] ?? ''));
        if (preg_match('/^([a-zA-Z0-9_]+)/', $service, $matches) === 1) {
            return $matches[1];
        }

        return $binding->type === 'redis' ? 'redis' : 'database';
    }

    private function releaseFailedBindingBeforeReplace(): void
    {
        $id = trim((string) ($this->bindingModalBindingId ?? ''));
        if ($id === '') {
            return;
        }

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($id)
            ->first();

        if (! $binding instanceof SiteBinding || ! $binding->isErrored()) {
            return;
        }

        $oldTargetId = (string) ($binding->target_id ?? '');
        $targetType = (string) ($binding->target_type ?? '');
        $binding->delete();
        $this->bindingModalBindingId = null;

        if ($targetType === 'cloud_database' && $oldTargetId !== '') {
            CloudDatabase::query()->whereKey($oldTargetId)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function isManagedBindingPlacement(array $config): bool
    {
        return ! empty($config['managed']) || ($config['placement'] ?? '') === 'managed';
    }

    private function canResizeManagedBinding(SiteBinding $binding, ?CloudDatabase $cluster): bool
    {
        $config = is_array($binding->config) ? $binding->config : [];

        return $binding->wasProvisionedByDply()
            && $binding->target_type === 'cloud_database'
            && $this->isManagedBindingPlacement($config)
            && $binding->status === SiteBinding::STATUS_CONFIGURED
            && ($config['resizing_to'] ?? '') === ''
            && $cluster instanceof CloudDatabase
            && $cluster->backend === CloudDatabase::BACKEND_DIGITALOCEAN
            && $cluster->isActive()
            && filled($cluster->backend_id);
    }

    public function canRetryBindingProvision(SiteBinding $binding): bool
    {
        $config = is_array($binding->config) ? $binding->config : [];
        $placement = $config['placement'] ?? '';

        if (! $binding->isErrored()) {
            return false;
        }

        if ($this->isManagedBindingPlacement($config)) {
            return true;
        }

        if (! in_array($placement, ['cache_vm', 'dedicated_vm', 'docker_vm'], true)) {
            return false;
        }

        if (filled($config['vm_size'] ?? null)) {
            return true;
        }

        return filled($binding->provisionServerId()) && filled($binding->target_id);
    }

    private function redispatchBindingProvisionWait(SiteBinding $binding): void
    {
        $config = is_array($binding->config) ? $binding->config : [];
        $placement = $config['placement'] ?? '';
        $serverId = (string) $binding->provisionServerId();
        $siteId = (string) $this->site->id;
        $targetId = (string) $binding->target_id;
        $bindingId = (string) $binding->id;

        match ($placement) {
            'cache_vm' => ProvisionDedicatedRedisVmJob::dispatch($serverId, $siteId, $targetId, $bindingId),
            'dedicated_vm' => ProvisionDedicatedDatabaseVmJob::dispatch($serverId, $siteId, $targetId, $bindingId),
            'docker_vm' => ProvisionDedicatedDockerDatabaseVmJob::dispatch($serverId, $siteId, $targetId, $bindingId),
            default => null,
        };
    }

    private function inFlightPrimaryBinding(string $type): ?SiteBinding
    {
        return $this->site->loadMissing('bindings')->bindings->first(
            fn (SiteBinding $binding): bool => $binding->type === $type
                && trim((string) (((array) $binding->config)['connection'] ?? '')) === ''
                && in_array($binding->status, [
                    SiteBinding::STATUS_PENDING,
                    SiteBinding::STATUS_PROVISIONING,
                    SiteBinding::STATUS_ERROR,
                ], true),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function bindingProvisionHint(SiteBinding $binding, array $config): string
    {
        if (! $binding->isProvisioning()) {
            return (string) Str::headline($binding->status);
        }

        if (! empty($config['managed'])) {
            return __('Provisioning the managed cluster — this takes a few minutes.');
        }

        return match ($config['placement'] ?? '') {
            'cache_vm' => __('Provisioning the dedicated Redis server — this can take several minutes.'),
            'dedicated_vm' => __('Provisioning the dedicated database server — this can take several minutes.'),
            'docker_vm' => __('Provisioning the Docker database server and starting the container — this can take several minutes.'),
            'docker' => __('Starting the Docker database container — this usually takes under a minute.'),
            default => __('Provisioning this resource — status updates as the job progresses.'),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function bindingPlacementLabel(array $config): ?string
    {
        if (! empty($config['managed'])) {
            return in_array((string) ($config['engine'] ?? ''), ['redis', 'valkey'], true)
                ? __('Managed Valkey')
                : __('Managed cluster');
        }

        return match ($config['placement'] ?? null) {
            'cache_vm' => __('Dedicated Redis server'),
            'dedicated_vm' => __('Dedicated database server'),
            'docker_vm' => __('Dedicated Docker database server'),
            'docker' => __('Docker container on this server'),
            'same_server' => __('This server'),
            null, '' => null,
            default => Str::headline((string) $config['placement']),
        };
    }

    /**
     * Mask a secret for display: keep a 3-char head/tail on longer values so the
     * operator can sanity-check it's the right credential without revealing it;
     * fully bullet short values where head/tail would leak most of the string.
     */
    private function maskBindingSecret(string $value): string
    {
        $len = mb_strlen($value);
        if ($len === 0) {
            return '';
        }
        if ($len <= 10) {
            return str_repeat('•', min($len, 12));
        }

        return mb_substr($value, 0, 3).' •••••• '.mb_substr($value, -3);
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

        // Databases stay scoped to the private network (listing the whole org
        // let a site re-point at a DB it can never dial). Redis also includes
        // same-org dedicated cache hosts, which are meant to be shared.
        $manager = app(SiteBindingManager::class);
        $serverIds = $binding->type === 'redis'
            ? $manager->attachableCacheServerIdsForSite($this->site)
            : $manager->reachableServerIdsForSite($this->site);
        if ($serverIds === []) {
            return [];
        }

        $row = fn ($r): array => [
            'id' => (string) $r->id,
            'label' => $r->name ?: ucfirst((string) $r->engine),
            'engine' => (string) $r->engine,
            'server' => $r->server?->name,
            'host' => $r->server?->private_ip_address ?: $r->server?->ip_address,
        ];

        return match ($binding->type) {
            'database' => ServerDatabase::query()->whereIn('server_id', $serverIds)->with('server')->get()->map($row)->values()->all(),
            'redis' => ServerCacheService::query()->whereIn('server_id', $serverIds)
                ->whereIn('engine', ServerCacheService::FAMILY_REDIS_ENGINES)->with('server')->get()->map($row)->values()->all(),
            default => [],
        };
    }

    /**
     * When the storage provider changes, reset the region to that provider's
     * first known region so the derived endpoint stays consistent — an AWS
     * region left selected after switching to Hetzner would build a bogus
     * endpoint. Custom providers carry no region list, so the field clears.
     * Also clears any saved-credential selection (it's provider-specific), and
     * when a saved credential is picked, pre-fills its stored region/endpoint.
     *
     * For the logging modal, clears provider-specific fields and pre-fills
     * from a saved credential when one is selected.
     */
    private function applyConnectedAppEnvPaste(): void
    {
        $blob = trim((string) ($this->bindingForm['env_paste'] ?? ''));
        if ($blob === '' || ! str_contains($blob, '=')) {
            return;
        }

        $provider = (string) ($this->bindingForm['provider'] ?? 'slack');
        $fields = ConnectedAppEnvPaste::fromBlob($provider, $blob);
        if ($fields === []) {
            $this->connectedAppPasteNote = __('No :app keys in that paste.', [
                'app' => app(SiteBindingManager::class)->connectedAppLabel($provider),
            ]);

            return;
        }

        foreach ($fields as $field => $value) {
            $this->bindingForm[$field] = $value;
        }
        $this->bindingForm['env_paste'] = '';
        $this->connectedAppPasteNote = trans_choice(
            '{1} Filled 1 field from the pasted .env.|[2,*] Filled :count fields from the pasted .env.',
            count($fields),
            ['count' => count($fields)],
        );
    }

    public function updatedBindingForm(mixed $value, ?string $key = null): void
    {
        if ($this->bindingModalType === 'mail') {
            // Switching provider clears the provider-specific secret fields and
            // any saved-credential pick (it's provider-scoped) so a stale value
            // from the previous provider can't leak into the new one. The shared
            // from-address/name persist across the switch.
            if ($key === 'provider') {
                foreach (['host', 'username', 'password', 'secret', 'domain', 'token', 'access_key_id', 'secret_access_key', 'region', 'key', 'account_id', 'credential_id'] as $f) {
                    $this->bindingForm[$f] = '';
                }
                $this->bindingForm['port'] = $value === 'smtp' ? '587' : '';
                $this->bindingForm['encryption'] = 'tls';
                $this->bindingForm['endpoint'] = $value === 'mailgun' ? 'api.mailgun.net' : '';

                // Cloudflare is the one provider with a guided/verified panel;
                // reset its transient state and default the sending domain to the
                // site's primary when switching to it.
                $this->resetCloudflareEmailGuidance();
                if ($value === 'cloudflare') {
                    $this->bindingForm['cf_domain'] = (string) ($this->site->primaryDomain()->hostname ?? '');
                }

                // Entering a chain mode seeds two legs; leaving it drops them.
                if (in_array($value, ['failover', 'roundrobin'], true)) {
                    $legs = is_array($this->bindingForm['legs'] ?? null) ? $this->bindingForm['legs'] : [];
                    if (count($legs) < 2) {
                        $this->bindingForm['legs'] = [$this->emptyMailLeg('smtp'), $this->emptyMailLeg('mailgun')];
                    }
                } else {
                    $this->bindingForm['legs'] = [];
                }
            }

            // A leg's provider changed → reset that leg's cred fields to the new
            // provider's blank defaults (keeps the chosen provider).
            if (is_string($key) && preg_match('/^legs\.(\d+)\.provider$/', $key, $m) === 1) {
                $i = (int) $m[1];
                $this->bindingForm['legs'][$i] = $this->emptyMailLeg((string) $value);
            }

            return;
        }

        if ($this->bindingModalType === 'search') {
            if ($key === 'provider') {
                foreach (['credential_id', 'app_id', 'secret', 'host', 'key', 'api_key'] as $f) {
                    $this->bindingForm[$f] = '';
                }
                $this->bindingForm['port'] = '8108';
                $this->bindingForm['protocol'] = 'http';
            }

            if ($key === 'credential_id' && is_string($value) && $value !== '') {
                $cred = SearchCredential::query()
                    ->where('organization_id', $this->site->organization_id)
                    ->where('provider', (string) ($this->bindingForm['provider'] ?? ''))
                    ->whereKey($value)
                    ->first();
                if ($cred instanceof SearchCredential) {
                    $c = $cred->credentials;
                    foreach (['app_id', 'secret', 'host', 'key', 'api_key', 'port', 'protocol'] as $f) {
                        $this->bindingForm[$f] = (string) ($c[$f] ?? $this->bindingForm[$f] ?? '');
                    }
                }
            }

            return;
        }

        if ($this->bindingModalType === 'payments') {
            if ($key === 'provider') {
                foreach (['credential_id', 'key', 'secret', 'currency', 'api_key', 'client_side_token', 'sandbox', 'webhook_secret'] as $f) {
                    $this->bindingForm[$f] = '';
                }
            }

            if ($key === 'credential_id' && is_string($value) && $value !== '') {
                $cred = PaymentCredential::query()
                    ->where('organization_id', $this->site->organization_id)
                    ->where('provider', (string) ($this->bindingForm['provider'] ?? ''))
                    ->whereKey($value)
                    ->first();
                if ($cred instanceof PaymentCredential) {
                    $c = $cred->credentials;
                    foreach (['key', 'secret', 'currency', 'api_key', 'client_side_token', 'sandbox', 'webhook_secret'] as $f) {
                        $this->bindingForm[$f] = (string) ($c[$f] ?? '');
                    }
                }
            }

            return;
        }

        if ($this->bindingModalType === 'oauth') {
            if ($key === 'provider') {
                foreach (['credential_id', 'client_id', 'client_secret'] as $f) {
                    $this->bindingForm[$f] = '';
                }
            }

            if ($key === 'credential_id' && is_string($value) && $value !== '') {
                $cred = OauthCredential::query()
                    ->where('organization_id', $this->site->organization_id)
                    ->where('provider', (string) ($this->bindingForm['provider'] ?? ''))
                    ->whereKey($value)
                    ->first();
                if ($cred instanceof OauthCredential) {
                    $c = $cred->credentials;
                    $this->bindingForm['client_id'] = (string) ($c['client_id'] ?? '');
                    $this->bindingForm['client_secret'] = (string) ($c['client_secret'] ?? '');
                }
            }

            return;
        }

        if ($this->bindingModalType === 'ai') {
            if ($key === 'provider') {
                foreach (['credential_id', 'api_key', 'organization'] as $f) {
                    $this->bindingForm[$f] = '';
                }
            }

            if ($key === 'credential_id' && is_string($value) && $value !== '') {
                $cred = AiCredential::query()
                    ->where('organization_id', $this->site->organization_id)
                    ->where('provider', (string) ($this->bindingForm['provider'] ?? ''))
                    ->whereKey($value)
                    ->first();
                if ($cred instanceof AiCredential) {
                    $c = $cred->credentials;
                    $this->bindingForm['api_key'] = (string) ($c['api_key'] ?? '');
                    $this->bindingForm['organization'] = (string) ($c['organization'] ?? '');
                }
            }

            return;
        }

        if ($this->bindingModalType === 'connected_app') {
            if ($key === 'provider') {
                foreach ([
                    'credential_id', 'bot_token', 'webhook_url', 'channel', 'chat_id',
                    'client_id', 'client_secret', 'refresh_token', 'folder_id',
                    'access_token', 'app_key', 'app_secret',
                ] as $f) {
                    $this->bindingForm[$f] = '';
                }
                $this->applyConnectedAppEnvPaste();
            }

            if ($key === 'env_paste') {
                $this->applyConnectedAppEnvPaste();
            }

            if ($key === 'credential_id' && is_string($value) && $value !== '') {
                $cred = ConnectedAppCredential::query()
                    ->where('organization_id', $this->site->organization_id)
                    ->where('provider', (string) ($this->bindingForm['provider'] ?? ''))
                    ->whereKey($value)
                    ->first();
                if ($cred instanceof ConnectedAppCredential) {
                    $c = is_array($cred->credentials) ? $cred->credentials : [];
                    foreach ([
                        'bot_token', 'webhook_url', 'channel', 'chat_id',
                        'client_id', 'client_secret', 'refresh_token', 'folder_id',
                        'access_token', 'app_key', 'app_secret',
                    ] as $f) {
                        $this->bindingForm[$f] = (string) ($c[$f] ?? '');
                    }
                }
            }

            return;
        }

        if ($this->bindingModalType === 'captcha') {
            if ($key === 'provider') {
                foreach (['credential_id', 'site_key', 'secret_key'] as $f) {
                    $this->bindingForm[$f] = '';
                }
            }

            if ($key === 'credential_id' && is_string($value) && $value !== '') {
                $cred = CaptchaCredential::query()
                    ->where('organization_id', $this->site->organization_id)
                    ->where('provider', (string) ($this->bindingForm['provider'] ?? ''))
                    ->whereKey($value)
                    ->first();
                if ($cred instanceof CaptchaCredential) {
                    $c = $cred->credentials;
                    $this->bindingForm['site_key'] = (string) ($c['site_key'] ?? '');
                    $this->bindingForm['secret_key'] = (string) ($c['secret_key'] ?? '');
                }
            }

            return;
        }

        if ($this->bindingModalType === 'sms') {
            if ($key === 'provider') {
                foreach (['credential_id', 'sid', 'auth_token', 'from', 'key', 'secret', 'server_key'] as $f) {
                    $this->bindingForm[$f] = '';
                }
            }

            if ($key === 'credential_id' && is_string($value) && $value !== '') {
                $cred = SmsCredential::query()
                    ->where('organization_id', $this->site->organization_id)
                    ->where('provider', (string) ($this->bindingForm['provider'] ?? ''))
                    ->whereKey($value)
                    ->first();
                if ($cred instanceof SmsCredential) {
                    $c = $cred->credentials;
                    foreach (['sid', 'auth_token', 'from', 'key', 'secret', 'server_key'] as $f) {
                        $this->bindingForm[$f] = (string) ($c[$f] ?? '');
                    }
                }
            }

            return;
        }

        if ($this->bindingModalType === 'error_tracking') {
            if ($key === 'provider') {
                foreach (['credential_id', 'dsn', 'traces_sample_rate', 'api_key', 'key', 'lookout_token', 'lookout_org'] as $f) {
                    $this->bindingForm[$f] = '';
                }
                $this->lookoutOrganizations = [];
            }

            // A new token invalidates any orgs loaded for the previous one.
            if ($key === 'lookout_token') {
                $this->lookoutOrganizations = [];
                $this->bindingForm['lookout_org'] = '';
            }

            if ($key === 'credential_id' && is_string($value) && $value !== '') {
                $provider = (string) ($this->bindingForm['provider'] ?? '');
                $cred = ErrorTrackingCredential::query()
                    ->where('organization_id', $this->site->organization_id)
                    ->where('provider', $provider)
                    ->whereKey($value)
                    ->first();

                if ($cred instanceof ErrorTrackingCredential) {
                    $credentials = $cred->credentials;
                    $this->bindingForm['dsn'] = (string) ($credentials['dsn'] ?? '');
                    $this->bindingForm['traces_sample_rate'] = (string) ($credentials['traces_sample_rate'] ?? '');
                    $this->bindingForm['api_key'] = (string) ($credentials['api_key'] ?? '');
                    $this->bindingForm['key'] = (string) ($credentials['key'] ?? '');
                    // A saved Lookout credential is the API token (+ its org), not
                    // a DSN — reusing it lets a new site mint its own project.
                    $this->bindingForm['lookout_token'] = (string) ($credentials['token'] ?? '');
                    $this->bindingForm['lookout_org'] = (string) ($credentials['organization_id'] ?? '');
                }
            }

            return;
        }

        if ($this->bindingModalType === 'logging') {
            if ($key === 'provider') {
                $this->bindingForm['credential_id'] = '';
                $this->bindingForm['host'] = '';
                $this->bindingForm['port'] = '';
                $this->bindingForm['source_token'] = '';
            }

            if ($key === 'credential_id' && is_string($value) && $value !== '') {
                $provider = (string) ($this->bindingForm['provider'] ?? '');
                $cred = LogDrainCredential::query()
                    ->where('organization_id', $this->site->organization_id)
                    ->where('provider', $provider)
                    ->whereKey($value)
                    ->first();

                if ($cred instanceof LogDrainCredential) {
                    $credentials = $cred->credentials;
                    $this->bindingForm['host'] = (string) ($credentials['host'] ?? '');
                    $this->bindingForm['port'] = (string) ($credentials['port'] ?? '');
                    $this->bindingForm['source_token'] = (string) ($credentials['source_token'] ?? '');
                }
            }

            return;
        }

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

    protected function dockerInstallIsInFlight(Server $server): bool
    {
        if ($this->dockerInstallRunId !== null) {
            return true;
        }

        return ServerManageAction::query()
            ->where('server_id', $server->id)
            ->where('task_name', 'manage-action:install_docker')
            ->whereIn('status', [
                ServerManageAction::STATUS_QUEUED,
                ServerManageAction::STATUS_RUNNING,
            ])
            ->exists();
    }

    protected function seedBindingConsoleAction(string $kind, string $label): ConsoleAction
    {
        if (method_exists($this, 'seedQueuedConsoleAction')) {
            return $this->seedQueuedConsoleAction($kind, $label);
        }

        return ConsoleAction::query()->create([
            'subject_type' => $this->site->getMorphClass(),
            'subject_id' => $this->site->id,
            'kind' => $kind,
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => auth()->id(),
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
    }

    protected function syncBindingServer(Server $server): void
    {
        $this->site->setRelation('server', $server);

        if (property_exists($this, 'server') && $this->server instanceof Server && (string) $this->server->id === (string) $server->id) {
            $this->server = $server;
        }
    }

    protected function markDockerEnginePresent(Server $server): void
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $manageDocker = is_array($meta['manage_docker'] ?? null) ? $meta['manage_docker'] : [];
        $meta['manage_docker'] = array_merge($manageDocker, ['present' => true]);
        $server->forceFill(['meta' => $meta])->save();
        $this->syncBindingServer($server->fresh() ?? $server);
    }
}
