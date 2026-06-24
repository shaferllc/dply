<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\EnsureSiteComposerPackageJob;
use App\Jobs\EnsureSitePhpRedisExtensionJob;
use App\Jobs\InstallCacheServiceJob;
use App\Jobs\SwitchCacheServiceJob;
use App\Jobs\TestBroadcastingBindingJob;
use App\Jobs\ValidateBindingConnectivityJob;
use App\Jobs\ValidateSiteBindingsReachableJob;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\SiteBinding;
use App\Support\Servers\CacheEngineAvailability;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 *
 * @method \App\Models\ConsoleAction seedQueuedConsoleAction(string $kind, ?string $label = null)
 * @method void watchConsoleAction(\App\Models\ConsoleAction $run, string $successToast, ?string $failureToast = null)
 */
trait VerifiesSiteBindings
{


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

    /**
     * After a Redis binding is attached, make sure the site's server actually
     * has the PHP `redis` client extension. Attaching Redis sets
     * REDIS_CLIENT=phpredis (and may flip cache/session/queue drivers to redis),
     * so a box missing the extension 500s at runtime with `Class "Redis" not
     * found`. Provisioning installs it only best-effort, so we check-and-install
     * here — the install is a cheap no-op when it's already present. Runs as its
     * own console-action so the operator sees the guarantee (and any failure)
     * in the page-top banner; SSH-capable hosts only (skips container/serverless).
     */
    private function ensurePhpRedisExtension(SiteBinding $binding): void
    {
        if ($binding->type !== 'redis') {
            return;
        }
        if ($this->site->server?->hostCapabilities()->supportsSsh() !== true) {
            return;
        }

        $run = $this->seedQueuedConsoleAction('site_remediate', __('Ensuring the PHP Redis extension'));

        EnsureSitePhpRedisExtensionJob::dispatch(
            (string) $run->id,
            (string) $this->site->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('The PHP Redis extension is installed — Redis is ready to use.'),
            __('Could not install the PHP Redis extension — the app may fail with Class "Redis" not found.'),
        );
    }

    /**
     * After a binding whose SDK ships as a Composer package is connected, make
     * sure the deployed app actually requires it — otherwise the injected env
     * (e.g. LOOKOUT_DSN) sits inert because the SDK never loads. Runs as its own
     * console-action so the operator sees the `composer require` in the page-top
     * banner; SSH-capable hosts only. No-ops when the package is already present.
     */
    private function ensureComposerPackage(SiteBinding $binding, string $package): void
    {
        if ($this->site->server?->hostCapabilities()->supportsSsh() !== true) {
            return;
        }

        $run = $this->seedQueuedConsoleAction('site_remediate', __('Installing :package', ['package' => $package]));

        EnsureSiteComposerPackageJob::dispatch(
            (string) $run->id,
            (string) $this->site->id,
            $package,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __(':package is installed — it ships on the next deploy.', ['package' => $package]),
            __('Could not install :package — add it to the app manually so the SDK loads.', ['package' => $package]),
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
}
