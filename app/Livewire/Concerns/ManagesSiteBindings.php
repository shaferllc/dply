<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\FixSiteBindingConnectivityJob;
use App\Jobs\InstallCacheServiceJob;
use App\Jobs\SendBindingTestEmailJob;
use App\Jobs\SwitchCacheServiceJob;
use App\Jobs\TestBroadcastingBindingJob;
use App\Jobs\ValidateBindingConnectivityJob;
use App\Jobs\ValidateSiteBindingsReachableJob;
use App\Models\AiCredential;
use App\Models\CaptchaCredential;
use App\Models\ErrorTrackingCredential;
use App\Models\LogDrainCredential;
use App\Models\MailCredential;
use App\Models\OauthCredential;
use App\Models\ObjectStorageCredential;
use App\Models\PaymentCredential;
use App\Models\ProviderCredential;
use App\Models\RealtimeApp;
use App\Models\SearchCredential;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\SiteBinding;
use App\Models\SmsCredential;
use App\Services\Deploy\DeploymentSecretInventory;
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

    /** Recipient for the mail binding's "send test email" action. */
    public string $mailTestRecipient = '';

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
        // database/redis probe their own endpoint; cache/queue/session probe the
        // underlying engine they ride on (resolved in ValidateBindingConnectivityJob).
        if (! in_array($binding->type, ['database', 'redis', 'cache', 'queue', 'session'], true)) {
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

    /**
     * Probe every networked binding (database/redis/storage/mail/broadcasting/
     * logging) for reachability from the site's server in one pass. Results land
     * on each binding's config.connectivity for the Resources map to badge.
     */
    public function validateReachability(): void
    {
        Gate::authorize('update', $this->site);

        if (! method_exists($this, 'seedQueuedConsoleAction') || ! method_exists($this, 'watchConsoleAction')) {
            return;
        }

        $run = $this->seedQueuedConsoleAction('bindings_reachable', __('Validating reachability'));

        ValidateSiteBindingsReachableJob::dispatch(
            (string) $run->id,
            (string) $this->site->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('Reachability check complete.'),
            __('Reachability check could not complete — see the console for details.'),
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

        SwitchCacheServiceJob::dispatch($existing->id, $targetEngine);

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
            $type === 'database' => $this->defaultDatabaseAttachBindingForm(),
            $type === 'redis' => ['target_id' => ''],
            $type === 'queue' => ['driver' => 'database'],
            $type === 'cache' => $this->defaultCacheBindingForm(),
            $type === 'session' => $this->defaultSessionBindingForm(),
            $type === 'storage' => $this->defaultStorageBindingForm($mode),
            $type === 'logging' => $this->defaultLoggingBindingForm(),
            $type === 'mail' => $this->defaultMailBindingForm(),
            $type === 'broadcasting' => $this->defaultBroadcastingBindingForm(),
            $type === 'error_tracking' => $this->defaultErrorTrackingBindingForm(),
            $type === 'ai' => $this->defaultAiBindingForm(),
            $type === 'captcha' => $this->defaultCaptchaBindingForm(),
            $type === 'sms' => $this->defaultSmsBindingForm(),
            $type === 'search' => $this->defaultSearchBindingForm(),
            $type === 'payments' => $this->defaultPaymentsBindingForm(),
            $type === 'oauth' => $this->defaultOauthBindingForm(),
            default => [],
        };
    }

    /**
     * Default form for attaching an existing database. Prefills from the current
     * binding so re-opening "Configure" keeps the primary DB selection and any
     * advanced options the operator set previously. Secrets (read replica
     * password) are never echoed back — they stay in the encrypted injected_env.
     *
     * @return array<string, mixed>
     */
    private function defaultDatabaseAttachBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'database');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'target_id' => (string) ($existing?->target_type === 'server_database' ? ($existing->target_id ?? '') : ''),
            // Read replica
            'read_replica_type' => (string) ($cfg['read_replica_type'] ?? ''),
            'read_replica_id' => (string) ($cfg['read_replica_id'] ?? ''),
            'read_replica_host' => (string) ($cfg['read_replica_host'] ?? ''),
            'read_replica_port' => (string) ($cfg['read_replica_port'] ?? ''),
            'read_replica_username' => (string) ($cfg['read_replica_username'] ?? ''),
            'read_replica_password' => '',
            // Tuning options — prefilled from stored config, blank = framework default
            'db_prefix' => (string) ($cfg['db_prefix'] ?? ''),
            'db_charset' => (string) ($cfg['db_charset'] ?? ''),
            'db_collation' => (string) ($cfg['db_collation'] ?? ''),
            'db_strict' => (string) ($cfg['db_strict'] ?? ''),
            'db_engine' => (string) ($cfg['db_engine'] ?? ''),
            'db_socket' => (string) ($cfg['db_socket'] ?? ''),
            'db_schema' => (string) ($cfg['db_schema'] ?? ''),
            'db_sslmode' => (string) ($cfg['db_sslmode'] ?? ''),
            'db_timezone' => (string) ($cfg['db_timezone'] ?? ''),
        ];
    }

    /**
     * Seed the broadcasting form. Prefills kind/driver/tier from an existing
     * binding so re-opening keeps the current choice. With no managed apps in
     * the org yet, defaults the managed path to "provision new" so a first-time
     * operator lands on the create flow rather than an empty picker.
     *
     * @return array<string, mixed>
     */
    private function defaultBroadcastingBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'broadcasting');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        $hasApps = RealtimeApp::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereIn('status', [RealtimeApp::STATUS_ACTIVE, RealtimeApp::STATUS_PROVISIONING])
            ->exists();

        $defaultTier = (string) config('realtime.default_tier', 'starter');

        return [
            'kind' => (string) ($cfg['kind'] ?? 'managed'),
            // Managed: attach an existing app vs provision a new (billed) one.
            'provision' => ! $hasApps,
            'realtime_app_id' => (string) ($existing?->target_type === 'realtime_app' ? $existing->target_id : ''),
            'tier' => array_key_exists((string) ($cfg['tier'] ?? ''), (array) config('realtime.tiers', []))
                ? (string) $cfg['tier']
                : $defaultTier,
            'app_name' => '',
            'confirm_charge' => false,
            // BYO.
            'driver' => (string) ($cfg['driver'] ?? 'pusher'),
            'pusher_app_id' => '',
            'pusher_app_key' => '',
            'pusher_app_secret' => '',
            'pusher_host' => '',
            'pusher_port' => '',
            'pusher_scheme' => 'https',
            'pusher_cluster' => '',
            'reverb_app_id' => '',
            'reverb_app_key' => '',
            'reverb_app_secret' => '',
            'reverb_host' => '',
            'reverb_port' => '',
            'reverb_scheme' => 'https',
            'ably_key' => '',
        ];
    }

    /**
     * Broadcasting connection tiers for the modal: slug → label, connection
     * cap, and monthly price in cents (see config('realtime.tiers')).
     *
     * @return array<string, array{label: string, max_connections: int, price_cents: int}>
     */
    public function broadcastingTiers(): array
    {
        $tiers = [];
        foreach ((array) config('realtime.tiers', []) as $slug => $tier) {
            $tiers[(string) $slug] = [
                'label' => (string) ($tier['label'] ?? ucfirst((string) $slug)),
                'max_connections' => (int) ($tier['max_connections'] ?? 0),
                'price_cents' => (int) ($tier['price_cents'] ?? 0),
            ];
        }

        return $tiers;
    }

    /**
     * Default mail transport form. Prefills provider + from-address/name from an
     * existing mail binding's config so re-opening "Configure" keeps the current
     * values; secrets are never echoed back (re-enter, or reuse a saved
     * credential). When there's no binding yet, the from-address is seeded from
     * the app's current MAIL_FROM_ADDRESS so configuring adopts it.
     *
     * @return array<string, mixed>
     */
    private function defaultMailBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'mail');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        $fromAddress = (string) ($cfg['from_address'] ?? '');
        $fromName = (string) ($cfg['from_name'] ?? '');

        if ($existing === null && ($fromAddress === '' || $fromName === '')) {
            $env = app(DeploymentSecretInventory::class)
                ->effectiveEnvironmentMapForSite($this->site);
            if ($fromAddress === '') {
                $fromAddress = trim((string) ($env['MAIL_FROM_ADDRESS'] ?? ''));
            }
            if ($fromName === '') {
                $fromName = trim((string) ($env['MAIL_FROM_NAME'] ?? ''));
            }
        }

        $provider = (string) ($cfg['provider'] ?? 'smtp');

        // Failover/round-robin: seed the leg rows from the saved chain (provider
        // slugs only — secrets are never echoed back, so each leg re-enters
        // creds or the chain re-saves them). Two empty legs when there's none.
        $legs = [];
        if (in_array($provider, ['failover', 'roundrobin'], true)) {
            foreach ((array) ($cfg['legs'] ?? []) as $slug) {
                $legs[] = $this->emptyMailLeg((string) $slug);
            }
        }
        if (in_array($provider, ['failover', 'roundrobin'], true) && count($legs) < 2) {
            $legs = [$this->emptyMailLeg('smtp'), $this->emptyMailLeg('mailgun')];
        }

        return [
            'provider' => $provider,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            // Failover / round-robin legs (each a flat provider+creds row).
            'legs' => $legs,
            // SMTP
            'host' => '',
            'port' => '587',
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            // Mailgun
            'secret' => '',
            'domain' => '',
            'endpoint' => 'api.mailgun.net',
            // Postmark
            'token' => '',
            // SES
            'access_key_id' => '',
            'secret_access_key' => '',
            'region' => '',
            // Resend
            'key' => '',
            // Saved-credential reuse + save-for-reuse.
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
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
            $env = app(DeploymentSecretInventory::class)
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
            // Every field is optional — blank means "use the framework default",
            // which attach materializes into the injected config.
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
     * @return array{0: string, 1: string} [key_source, provider_credential_id]
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
     * Default logging drain form. Prefills provider from an existing binding's
     * config so re-opening "Edit" keeps the current provider selected.
     *
     * @return array<string, mixed>
     */
    private function defaultLoggingBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'logging');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'papertrail'),
            'host' => '',
            'port' => '',
            'source_token' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * Default error-tracking form. Prefills provider from an existing binding's
     * config so re-opening "Configure" keeps the current provider selected;
     * secrets (DSN/key) are never echoed back.
     *
     * @return array<string, mixed>
     */
    private function defaultErrorTrackingBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'error_tracking');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'sentry'),
            // Sentry
            'dsn' => '',
            'traces_sample_rate' => '',
            // Bugsnag
            'api_key' => '',
            // Flare
            'key' => '',
            // Saved-credential reuse + save-for-reuse.
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * Saved error-tracking credentials the site's org can reuse for $provider.
     *
     * @return list<array{id: string, label: string}>
     */
    public function errorTrackingCredentialsFor(string $provider): array
    {
        return ErrorTrackingCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (ErrorTrackingCredential $c): array => [
                'id' => (string) $c->id,
                'label' => (string) $c->name,
            ])
            ->all();
    }

    public function deleteErrorTrackingCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = ErrorTrackingCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof ErrorTrackingCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved error tracking credential removed.'));
    }

    /**
     * Default AI/LLM key form. Prefills provider from an existing binding so
     * re-opening keeps the selection; the key is never echoed back.
     *
     * @return array<string, mixed>
     */
    private function defaultAiBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'ai');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'openai'),
            'api_key' => '',
            'organization' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function aiCredentialsFor(string $provider): array
    {
        return AiCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (AiCredential $c): array => ['id' => (string) $c->id, 'label' => (string) $c->name])
            ->all();
    }

    public function deleteAiCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = AiCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof AiCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved AI credential removed.'));
    }

    /**
     * Default CAPTCHA form. Prefills provider from an existing binding; keys are
     * never echoed back.
     *
     * @return array<string, mixed>
     */
    private function defaultCaptchaBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'captcha');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'turnstile'),
            'site_key' => '',
            'secret_key' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function captchaCredentialsFor(string $provider): array
    {
        return CaptchaCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (CaptchaCredential $c): array => ['id' => (string) $c->id, 'label' => (string) $c->name])
            ->all();
    }

    public function deleteCaptchaCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = CaptchaCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof CaptchaCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved CAPTCHA credential removed.'));
    }

    /**
     * Default SMS / push form. Prefills provider from an existing binding;
     * secrets are never echoed back.
     *
     * @return array<string, mixed>
     */
    private function defaultSmsBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'sms');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'twilio'),
            // Twilio
            'sid' => '',
            'auth_token' => '',
            // Twilio + Vonage share a from number.
            'from' => '',
            // Vonage
            'key' => '',
            'secret' => '',
            // FCM
            'server_key' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function smsCredentialsFor(string $provider): array
    {
        return SmsCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (SmsCredential $c): array => ['id' => (string) $c->id, 'label' => (string) $c->name])
            ->all();
    }

    public function deleteSmsCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = SmsCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof SmsCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved SMS credential removed.'));
    }

    /**
     * Default search (Scout) form. Prefills provider from an existing binding;
     * secrets are never echoed back.
     *
     * @return array<string, mixed>
     */
    private function defaultSearchBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'search');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'meilisearch'),
            // Algolia
            'app_id' => '',
            'secret' => '',
            // Meilisearch + Typesense share host.
            'host' => '',
            'key' => '',
            // Typesense
            'port' => '8108',
            'protocol' => 'http',
            'api_key' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function searchCredentialsFor(string $provider): array
    {
        return SearchCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (SearchCredential $c): array => ['id' => (string) $c->id, 'label' => (string) $c->name])
            ->all();
    }

    public function deleteSearchCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = SearchCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof SearchCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved search credential removed.'));
    }

    /**
     * Default payments form. Prefills provider from an existing binding; secrets
     * are never echoed back.
     *
     * @return array<string, mixed>
     */
    private function defaultPaymentsBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'payments');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'stripe'),
            // Stripe
            'key' => '',
            'secret' => '',
            'currency' => '',
            // Paddle
            'api_key' => '',
            'client_side_token' => '',
            'sandbox' => '',
            // Shared
            'webhook_secret' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function paymentCredentialsFor(string $provider): array
    {
        return PaymentCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (PaymentCredential $c): array => ['id' => (string) $c->id, 'label' => (string) $c->name])
            ->all();
    }

    public function deletePaymentCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = PaymentCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof PaymentCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved payments credential removed.'));
    }

    /**
     * The Cashier webhook URL preview for the current payments provider, derived
     * from the site's primary hostname — shown in the modal so the operator can
     * register it. Null when the site has no public URL yet.
     */
    public function paymentsWebhookPreview(string $provider): ?string
    {
        $host = $this->site->primaryDomain()?->hostname;
        if (! is_string($host) || trim($host) === '') {
            $host = $this->site->testingHostname();
        }
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return null;
        }

        $path = $provider === 'paddle' ? '/paddle/webhook' : '/stripe/webhook';

        return 'https://'.$host.$path;
    }

    /**
     * Default OAuth form. Prefills provider from an existing binding; the
     * redirect override is left blank so attach auto-derives it.
     *
     * @return array<string, mixed>
     */
    private function defaultOauthBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'oauth');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'github'),
            'client_id' => '',
            'client_secret' => '',
            // Blank => auto-derive {site}/auth/{provider}/callback.
            'redirect' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * The auto-derived OAuth redirect URL preview for the current provider,
     * shown in the modal (the footgun this binding removes). Null when the site
     * has no public URL yet.
     */
    public function oauthRedirectPreview(string $provider): ?string
    {
        $host = $this->site->primaryDomain()?->hostname;
        if (! is_string($host) || trim($host) === '') {
            $host = $this->site->testingHostname();
        }
        $host = strtolower(trim((string) $host));

        return $host !== '' ? 'https://'.$host.'/auth/'.$provider.'/callback' : null;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function oauthCredentialsFor(string $provider): array
    {
        return OauthCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (OauthCredential $c): array => ['id' => (string) $c->id, 'label' => (string) $c->name])
            ->all();
    }

    public function deleteOauthCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = OauthCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof OauthCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved OAuth credential removed.'));
    }

    /**
     * Saved log drain credentials the site's org can reuse for $provider.
     *
     * @return list<array{id: string, label: string}>
     */
    public function logDrainCredentialsFor(string $provider): array
    {
        return LogDrainCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (LogDrainCredential $c): array => [
                'id' => (string) $c->id,
                'label' => (string) $c->name,
            ])
            ->all();
    }

    public function deleteLogDrainCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = LogDrainCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof LogDrainCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved log drain credential removed.'));
    }

    /**
     * A blank failover/round-robin leg: provider slug + every per-provider cred
     * field (only the chosen provider's are used; the rest stay empty).
     *
     * @return array<string, string>
     */
    private function emptyMailLeg(string $provider = 'smtp'): array
    {
        return [
            'provider' => $provider,
            // smtp
            'host' => '', 'port' => '587', 'username' => '', 'password' => '', 'encryption' => 'tls',
            // mailgun
            'secret' => '', 'domain' => '', 'endpoint' => 'api.mailgun.net',
            // postmark
            'token' => '',
            // ses
            'access_key_id' => '', 'secret_access_key' => '', 'region' => '',
            // resend
            'key' => '',
        ];
    }

    /** Append a leg to the failover/round-robin chain in the open modal. */
    public function addMailLeg(): void
    {
        $legs = is_array($this->bindingForm['legs'] ?? null) ? $this->bindingForm['legs'] : [];
        $legs[] = $this->emptyMailLeg('mailgun');
        $this->bindingForm['legs'] = array_values($legs);
    }

    /** Remove a leg by index; the chain keeps at least two. */
    public function removeMailLeg(int $index): void
    {
        $legs = is_array($this->bindingForm['legs'] ?? null) ? array_values($this->bindingForm['legs']) : [];
        if (count($legs) <= 2) {
            return;
        }
        unset($legs[$index]);
        $this->bindingForm['legs'] = array_values($legs);
    }

    /**
     * The config/mail.php snippet the operator must paste for a failover /
     * round-robin chain — the one piece dply can't inject (the chain order
     * lives in committed code). Built from the legs currently in the modal.
     */
    public function mailFailoverSnippet(string $transport, array $legs): string
    {
        $slugs = [];
        foreach ($legs as $leg) {
            $p = is_array($leg) ? strtolower(trim((string) ($leg['provider'] ?? ''))) : '';
            if ($p !== '') {
                $slugs[] = "            '".$p."',";
            }
        }
        $list = implode("\n", $slugs);

        return "'mailers' => [\n"
            ."    '".$transport."' => [\n"
            ."        'transport' => '".$transport."',\n"
            ."        'mailers' => [\n".$list."\n        ],\n"
            ."    ],\n"
            ."    // … keep your existing mailer entries\n"
            ."],\n\n"
            ."'default' => env('MAIL_MAILER', '".$transport."'),";
    }

    /**
     * Saved mail credentials the site's org can reuse for $provider, for the
     * mail modal's "Use saved keys" picker.
     *
     * @return list<array{id: string, label: string}>
     */
    public function mailCredentialsFor(string $provider): array
    {
        return MailCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get()
            ->map(fn (MailCredential $c): array => [
                'id' => (string) $c->id,
                'label' => (string) $c->name,
            ])
            ->all();
    }

    public function deleteMailCredential(string $credentialId): void
    {
        Gate::authorize('update', $this->site);

        $cred = MailCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($credentialId)
            ->first();

        if (! $cred instanceof MailCredential) {
            return;
        }

        $cred->delete();

        if (($this->bindingForm['credential_id'] ?? '') === $credentialId) {
            $this->bindingForm['credential_id'] = '';
        }

        $this->toastSuccess(__('Saved mail credential removed.'));
    }

    /**
     * Send a test email from the site's server using the persisted mail
     * binding's transport, to confirm the deployed app can actually deliver.
     * Runs server-side (queued SSH) because that's the box that will send at
     * runtime — and it reuses the transport packages already in the app's
     * vendor/. Requires the console-action plumbing (deploy hub) + a deployed
     * site (vendor present); both are checked in the job / surfaced as copy.
     */
    public function sendBindingTestEmail(string $bindingId): void
    {
        Gate::authorize('update', $this->site);

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof SiteBinding || $binding->type !== 'mail') {
            return;
        }

        if (! method_exists($this, 'seedQueuedConsoleAction') || ! method_exists($this, 'watchConsoleAction')) {
            $this->toastError(__('Sending a test email is available from the deploy hub.'));

            return;
        }

        $recipient = trim($this->mailTestRecipient) ?: (string) (auth()->user()?->email ?? '');
        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->toastError(__('Enter a valid email address to send the test to.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('mail_test', __('Sending test email'));

        SendBindingTestEmailJob::dispatch(
            (string) $run->id,
            (string) $this->site->id,
            (string) $binding->id,
            $recipient,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('Test email sent to :to — check the inbox (and spam).', ['to' => $recipient]),
            __('The test email could not be sent — see the console for the transport error.'),
        );
    }

    /**
     * Test a broadcasting binding. For a managed dply Realtime app, publish a
     * harmless test event to the relay from the control plane (no SSH) — a 2xx
     * proves the relay is live and the app credentials are accepted. BYO
     * bindings have no managed relay, so they fall back to the TCP reachability
     * probe (which already resolves PUSHER_HOST:443 via BindingReachability).
     * Either path records config.connectivity so the card badge flips.
     */
    public function testBroadcastingBinding(string $bindingId): void
    {
        Gate::authorize('update', $this->site);

        $binding = SiteBinding::query()
            ->where('site_id', $this->site->id)
            ->whereKey($bindingId)
            ->first();

        if (! $binding instanceof SiteBinding || $binding->type !== 'broadcasting') {
            return;
        }

        // BYO broadcasting points at the operator's own Pusher/Reverb/Ably — no
        // managed app to authenticate against, so probe TCP reachability from
        // the server like the other networked bindings.
        if ($binding->target_type !== 'realtime_app') {
            $this->validateBindingConnectivity($binding);

            return;
        }

        if (! method_exists($this, 'seedQueuedConsoleAction') || ! method_exists($this, 'watchConsoleAction')) {
            $this->toastError(__('Testing broadcasting is available from the deploy hub.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('broadcasting_test', __('Testing broadcasting'));

        TestBroadcastingBindingJob::dispatch(
            (string) $run->id,
            (string) $this->site->id,
            (string) $binding->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('Broadcasting relay reachable — a test event published successfully.'),
            __('Could not publish a test event to the relay — see the console for details.'),
        );
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
                foreach (['host', 'username', 'password', 'secret', 'domain', 'token', 'access_key_id', 'secret_access_key', 'region', 'key', 'credential_id'] as $f) {
                    $this->bindingForm[$f] = '';
                }
                $this->bindingForm['port'] = $value === 'smtp' ? '587' : '';
                $this->bindingForm['encryption'] = 'tls';
                $this->bindingForm['endpoint'] = $value === 'mailgun' ? 'api.mailgun.net' : '';

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
                    $c = is_array($cred->credentials) ? $cred->credentials : [];
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
                    $c = is_array($cred->credentials) ? $cred->credentials : [];
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
                    $c = is_array($cred->credentials) ? $cred->credentials : [];
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
                    $c = is_array($cred->credentials) ? $cred->credentials : [];
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
                    $c = is_array($cred->credentials) ? $cred->credentials : [];
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
                    $c = is_array($cred->credentials) ? $cred->credentials : [];
                    foreach (['sid', 'auth_token', 'from', 'key', 'secret', 'server_key'] as $f) {
                        $this->bindingForm[$f] = (string) ($c[$f] ?? '');
                    }
                }
            }

            return;
        }

        if ($this->bindingModalType === 'error_tracking') {
            if ($key === 'provider') {
                foreach (['credential_id', 'dsn', 'traces_sample_rate', 'api_key', 'key'] as $f) {
                    $this->bindingForm[$f] = '';
                }
            }

            if ($key === 'credential_id' && is_string($value) && $value !== '') {
                $provider = (string) ($this->bindingForm['provider'] ?? '');
                $cred = ErrorTrackingCredential::query()
                    ->where('organization_id', $this->site->organization_id)
                    ->where('provider', $provider)
                    ->whereKey($value)
                    ->first();

                if ($cred instanceof ErrorTrackingCredential) {
                    $credentials = is_array($cred->credentials) ? $cred->credentials : [];
                    $this->bindingForm['dsn'] = (string) ($credentials['dsn'] ?? '');
                    $this->bindingForm['traces_sample_rate'] = (string) ($credentials['traces_sample_rate'] ?? '');
                    $this->bindingForm['api_key'] = (string) ($credentials['api_key'] ?? '');
                    $this->bindingForm['key'] = (string) ($credentials['key'] ?? '');
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
                    $credentials = is_array($cred->credentials) ? $cred->credentials : [];
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
