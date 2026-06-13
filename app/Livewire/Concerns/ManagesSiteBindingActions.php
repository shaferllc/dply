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
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\SiteBinding;
use App\Models\SmsCredential;
use App\Services\Deploy\SiteBindingManager;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
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
        $this->dispatch('open-modal', 'site-binding-modal');
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
        $this->resetErrorBag();
    }

    public function saveBinding(SiteBindingManager $manager): void
    {
        Gate::authorize('update', $this->site);

        // Carry which row (if any) we're editing so multi-instance types (storage)
        // can update that row instead of rejecting it as a duplicate disk name.
        $params = $this->bindingForm + ['binding_id' => $this->bindingModalBindingId];

        try {
            $binding = $this->bindingModalMode === 'provision'
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

        $this->validateBindingConnectivity($binding);
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
