<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\FixSiteBindingConnectivityJob;
use App\Models\AiCredential;
use App\Models\CaptchaCredential;
use App\Models\ErrorTrackingCredential;
use App\Models\LogDrainCredential;
use App\Models\OauthCredential;
use App\Models\ObjectStorageCredential;
use App\Models\PaymentCredential;
use App\Models\SearchCredential;
use App\Models\ProviderCredential;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\SiteBinding;
use App\Models\SmsCredential;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Modules\Database\Actions\CreateDedicatedDatabaseVm;
use App\Modules\Database\Backends\DatabaseRouter;
use App\Modules\Database\Support\DedicatedDatabaseVm;
use App\Modules\Database\Support\ServerlessDatabaseVendors;
use App\Modules\Deploy\Services\SiteBindingManager;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 *
 * @method \App\Models\ConsoleAction seedQueuedConsoleAction(string $kind, ?string $label = null)
 * @method void watchConsoleAction(\App\Models\ConsoleAction $run, string $successToast, ?string $failureToast = null)
 */
trait ManagesSiteBindingActions
{


    public function openBindingModal(string $type, string $mode = 'attach', ?string $bindingId = null): void
    {
        Gate::authorize('update', $this->site);

        $this->resetErrorBag();
        $this->bindingModalType = $type;
        $this->bindingModalMode = $mode === 'provision' ? 'provision' : 'attach';
        $this->bindingModalBindingId = null;
        $this->bindingForm = $this->defaultBindingForm($type, $this->bindingModalMode);

        // Editing a specific existing binding (multi-instance types like storage):
        // pre-fill the non-secret fields from that row. Secrets are never echoed,
        // so the operator re-supplies keys (or reuses a saved credential).
        if ($bindingId !== null) {
            $this->seedBindingFormForEdit($type, $bindingId);
        }

        $this->bindingTargets = app(SiteBindingManager::class)->attachableTargets($this->site, $type);

        // A dedicated-DB-VM placement needs a size list (provider/region
        // specific); fetch it up front so the modal can render the picker.
        $this->dedicatedVmSizes = [];
        if ($type === 'database' && $this->bindingModalMode === 'provision') {
            $this->loadDedicatedVmSizes();
        }

        $this->dispatch('open-modal', 'site-binding-modal');
    }

    /**
     * Populate {@see $dedicatedVmSizes} from the customer-connected create
     * catalog for the app server's provider + region. Best-effort: a provider
     * API failure just leaves the list empty (the dedicated card shows
     * unavailable) rather than breaking the modal.
     */
    private function loadDedicatedVmSizes(): void
    {
        $server = $this->site->server;
        if ($server === null || ! DedicatedDatabaseVm::eligible($server) || $this->site->organization === null) {
            return;
        }

        try {
            $catalog = app(ResolveServerCreateCatalog::class)->handle(
                $this->site->organization,
                $server->provider->value,
                (string) $server->provider_credential_id,
                (string) $server->region,
            );
            $this->dedicatedVmSizes = collect($catalog['sizes'] ?? [])
                ->map(fn ($s): array => [
                    'value' => (string) ($s['value'] ?? ''),
                    'label' => (string) ($s['label'] ?? ($s['value'] ?? '')),
                ])
                ->filter(fn (array $s): bool => $s['value'] !== '')
                ->values()
                ->all();

            // Preselect the first size so the dedicated card has a valid value
            // the moment it's chosen (the field is shared via bindingForm).
            if ($this->dedicatedVmSizes !== [] && ($this->bindingForm['vm_size'] ?? '') === '') {
                $this->bindingForm['vm_size'] = $this->dedicatedVmSizes[0]['value'];
            }
        } catch (\Throwable $e) {
            $this->dedicatedVmSizes = [];
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

        try {
            app(CreateDedicatedDatabaseVm::class)->handle($this, $this->site, $this->bindingForm);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site = $this->site->fresh() ?? $this->site;
        $this->dispatch('close-modal', 'site-binding-modal');
        $this->toastSuccess(__('Provisioning a dedicated database server — this can take several minutes.'));
    }

    /**
     * Placement options for the "Provision new database" modal: always
     * on-box, plus a co-located managed cluster when the server's provider
     * offers one (DigitalOcean today). Each option carries the engines it
     * supports so the modal can filter as the operator picks an engine, and
     * an `available` flag (false when the managed backend exists but no
     * provider credential is connected). Region/cost are display-only.
     *
     * @return list<array{key: string, label: string, sublabel: string, available: bool, note: ?string, engines: list<string>}>
     */
    public function databasePlacements(): array
    {
        $options = [[
            'key' => 'on_box',
            'label' => __('On this server'),
            'sublabel' => __('Free · shares the box'),
            'available' => true,
            'note' => null,
            'engines' => ['mysql', 'postgres', 'sqlite'],
        ]];

        $server = $this->site->server;
        if ($server === null) {
            return $options;
        }

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

            $sublabel = implode(' · ', array_filter([
                $region,
                $cost !== null ? '~$'.$cost.'/mo' : null,
                __('isolated, billed by :provider', ['provider' => $server->provider->label()]),
            ]));

            $options[] = [
                'key' => 'managed',
                'label' => $server->provider->label().' '.__('Managed'),
                'sublabel' => $sublabel,
                'available' => $hasCredential && $region !== null,
                'note' => $hasCredential ? null : __('Connect a :provider credential first', ['provider' => $server->provider->label()]),
                'engines' => $backend->supportedEngines(),
            ];
        }

        // Dedicated DB VM: a brand-new server on the customer's provider whose
        // only job is this database. Needs a size list (loaded on modal open).
        if (DedicatedDatabaseVm::eligible($server)) {
            $sizesReady = $this->dedicatedVmSizes !== [];
            $options[] = [
                'key' => 'dedicated_vm',
                'label' => __('Dedicated database server'),
                'sublabel' => implode(' · ', array_filter([
                    (string) $server->region,
                    __('new :provider VM · isolated host', ['provider' => $server->provider->label()]),
                ])),
                'available' => $sizesReady,
                'note' => $sizesReady ? null : __('No sizes available for this provider/region.'),
                'engines' => DedicatedDatabaseVm::supportedEngines(),
            ];
        }

        // BYO serverless vendors (Neon …): region-agnostic, always offered.
        foreach (ServerlessDatabaseVendors::all() as $vendor) {
            $options[] = [
                'key' => $vendor['key'],
                'label' => $vendor['label'],
                'sublabel' => __('serverless · bring your own account'),
                'available' => true,
                'note' => null,
                'engines' => $vendor['engines'],
                'serverless' => true,
                'regions' => $vendor['regions'],
            ];
        }

        return $options;
    }

    /**
     * Pre-fill {@see $bindingForm} from an existing binding row for editing.
     * Currently only `storage` supports multiple rows per site; other types
     * already round-trip via their own default-form prefill.
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
        $this->bindingForm = $this->defaultBindingForm($this->bindingModalType, $mode);
        $this->bindingTargets = app(SiteBindingManager::class)->attachableTargets($this->site, $this->bindingModalType);

        // Toggling into "Provision new" for a database must load the dedicated-VM
        // size catalog too, or that placement card stays disabled.
        $this->dedicatedVmSizes = [];
        if ($this->bindingModalType === 'database' && $mode === 'provision') {
            $this->loadDedicatedVmSizes();
        }

        $this->resetErrorBag();
    }

    public function saveBinding(SiteBindingManager $manager): void
    {
        Gate::authorize('update', $this->site);

        // The dedicated-DB-VM placement provisions a whole new server, which
        // means driving the customer-connected create pipeline (a Livewire Form
        // object) — handled in the component layer, not the binding manager.
        if ($this->bindingModalType === 'database'
            && $this->bindingModalMode === 'provision'
            && ($this->bindingForm['placement'] ?? '') === 'dedicated_vm') {
            $this->provisionDedicatedDatabaseVm();

            return;
        }

        // Auto-provision Redis on connect: when there's no Redis to attach AND
        // none is installed on the box, kick the install right from the connect
        // action instead of dead-ending on "nothing reachable" (the operator no
        // longer has to spot and click the separate Install Redis button). Once
        // it's running, reconnect attaches it. If Redis IS installed but just
        // unreachable, fall through so attach surfaces the precise error.
        if ($this->bindingModalType === 'redis' && $this->maybeAutoInstallRedis($manager)) {
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
            && (((array) $binding->config)['provider'] ?? '') === 'lookout'
            && method_exists($this, 'ensureComposerPackage')) {
            $this->ensureComposerPackage($binding, 'lookout/tracing');
        }

        // Connecting a mail transport must "just work" too: API-based providers
        // (Cloudflare, Mailgun, Postmark, Resend, SendGrid, SES) ship their
        // Symfony transport — and its HTTP client — as separate Composer packages.
        // Without them the app (and a test-send) dies with `Class "…HttpClient"
        // not found`. Mirror the Lookout path — add each leg's package on the box
        // now (no-op when present) — so the binding sends instead of fataling.
        if ($binding->type === 'mail' && method_exists($this, 'ensureComposerPackage')) {
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

        $orgs = app(\App\Modules\Deploy\Services\LookoutProvisioner::class)->organizations($token);
        $this->lookoutOrganizations = $orgs;

        if ($orgs === []) {
            $this->toastError(__('Could not load organizations — check the token, or enter the organization ID manually.'));

            return;
        }

        if (count($orgs) === 1) {
            $this->bindingForm['lookout_org'] = $orgs[0]['id'];
        }
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

        // Only offer backends this site can actually reach: its own server
        // (loopback) plus same-private-network peers. Listing the whole org let a
        // site re-point at a database on an unrelated network it can never dial.
        $reachableServerIds = app(SiteBindingManager::class)->reachableServerIdsForSite($this->site);
        if ($reachableServerIds === []) {
            return [];
        }

        $row = fn ($r): array => [
            'id' => (string) $r->id,
            'label' => $r->name ?: ucfirst((string) $r->engine),
            'engine' => (string) $r->engine,
            'server' => $r->server?->name,
            'host' => $r->server?->private_ip_address,
        ];

        return match ($binding->type) {
            'database' => ServerDatabase::query()->whereIn('server_id', $reachableServerIds)->with('server')->get()->map($row)->values()->all(),
            'redis' => ServerCacheService::query()->whereIn('server_id', $reachableServerIds)
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
                    $this->bindingForm['cf_domain'] = (string) ($this->site->primaryDomain()?->hostname ?? '');
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
}
