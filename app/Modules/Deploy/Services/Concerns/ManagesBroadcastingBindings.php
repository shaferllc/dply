<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Modules\Realtime\Actions\CreateRealtimeApp;
use App\Models\Organization;
use App\Modules\Realtime\Models\RealtimeApp;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Services\Servers\SupervisorProvisioner;
use App\Modules\Realtime\Services\RealtimeBackendFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * Attach / provision the `broadcasting` binding type (managed dply Realtime
 * apps + BYO Pusher/Reverb/Ably) and tear managed infra down on detach.
 */
trait ManagesBroadcastingBindings
{
    /** BYO broadcasting drivers the operator can wire their own credentials for. */
    private const BROADCAST_BYO_DRIVERS = ['pusher', 'reverb', 'ably', 'log', 'null'];

    /** Loopback ports self-hosted Reverb daemons are allocated from. */
    private const REVERB_PORT_RANGE = [8080, 8180];

    /**
     * Managed broadcasting apps in the site's org an operator can attach (share
     * one app across sites). Active + still-provisioning apps are listed so a
     * freshly-created app shows up immediately.
     *
     * @return list<array{id: string, label: string}>
     */
    private function attachableRealtimeApps(Site $site): array
    {
        $apps = RealtimeApp::query()
            ->where('organization_id', $site->organization_id)
            ->whereIn('status', [RealtimeApp::STATUS_ACTIVE, RealtimeApp::STATUS_PROVISIONING])
            ->orderBy('name')
            ->get();

        $consumers = $this->bindingConsumerCounts(
            'realtime_app',
            $apps->map(fn (RealtimeApp $a): string => (string) $a->id)->all(),
            (string) $site->id,
        );

        return $apps
            ->map(function (RealtimeApp $app) use ($consumers): array {
                $used = $consumers[(string) $app->id] ?? 0;

                return [
                    'id' => (string) $app->id,
                    'label' => $app->name.' · '.$app->tierConfig()['label'].$this->usageSuffix($used),
                    'group' => 'app',
                    'consumers' => $used,
                ];
            })
            ->all();
    }

    /**
     * Configure how the app broadcasts. Three paths share one binding:
     *  - kind=managed → a dply-managed RealtimeApp (Cloudflare relay), either an
     *    existing app (shared across sites) or a freshly provisioned, billed one.
     *  - kind=self_hosted → Laravel Reverb on the site's OWN server: dply mints
     *    the credentials, allocates a loopback port and stands the daemon up.
     *  - kind=byo     → the operator's own Pusher/Reverb/Ably (or log/null).
     * Both inject BROADCAST_CONNECTION + the driver's connection vars, plus the
     * VITE_ mirror so Laravel Echo works without hand-adding client vars.
     *
     * @param  array<string, mixed> $params
     */
    private function attachBroadcasting(Site $site, array $params): SiteBinding
    {
        $kind = strtolower(trim((string) ($params['kind'] ?? 'managed')));

        return match ($kind) {
            'managed' => $this->attachManagedBroadcasting($site, $params),
            'self_hosted' => $this->attachSelfHostedReverb($site),
            'byo' => $this->attachByoBroadcasting($site, $params),
            default => throw new InvalidArgumentException(__('Choose a broadcasting option.')),
        };
    }

    /**
     * @param  array<string, mixed> $params
     */
    private function attachManagedBroadcasting(Site $site, array $params): SiteBinding
    {
        // The managed (dply-hosted, billed) relay is gated behind surface.realtime.
        // BYO broadcasting stays available regardless. Guard the server side so the
        // billed path can't be reached via a crafted request when the flag is off.
        $org = Organization::query()->find($site->organization_id);
        if (! $org instanceof Organization || ! Feature::for($org)->active('surface.realtime')) {
            throw new InvalidArgumentException(__('Managed broadcasting is not available. Bring your own broadcasting credentials instead.'));
        }

        if ((bool) ($params['provision'] ?? false)) {
            return $this->provisionManagedBroadcasting($site, $params);
        }

        $appId = (string) ($params['realtime_app_id'] ?? '');
        if ($appId === '') {
            throw new InvalidArgumentException(__('Choose a broadcasting app to attach.'));
        }

        $app = RealtimeApp::query()
            ->where('organization_id', $site->organization_id)
            ->whereKey($appId)
            ->first();

        if (! $app instanceof RealtimeApp) {
            throw new InvalidArgumentException(__('That broadcasting app is not available.'));
        }

        return $this->persist($site, 'broadcasting', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $app->name,
            'target_type' => 'realtime_app',
            'target_id' => (string) $app->id,
            'injected_env' => $this->managedBroadcastingEnv($app),
            'config' => ['kind' => 'managed', 'tier' => $app->tierSlug()],
        ]);
    }

    /**
     * @param  array<string, mixed> $params
     */
    private function provisionManagedBroadcasting(Site $site, array $params): SiteBinding
    {
        // The dply relay is the one billed path — confirm the charge before we
        // create (and start billing) a new app.
        if (! (bool) ($params['confirm_charge'] ?? false)) {
            throw new InvalidArgumentException(__('Please confirm the monthly charge to provision a managed broadcasting app.'));
        }

        $org = Organization::query()->find($site->organization_id);
        if (! $org instanceof Organization) {
            throw new RuntimeException(__('This site has no organization to bill the broadcasting app to.'));
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            throw new RuntimeException(__('You must be signed in to provision a managed broadcasting app.'));
        }

        $tier = (string) ($params['tier'] ?? config('realtime.default_tier'));
        if (! array_key_exists($tier, (array) config('realtime.tiers', []))) {
            throw new InvalidArgumentException(__('Unknown broadcasting tier.'));
        }

        $name = trim((string) ($params['app_name'] ?? '')) ?: (string) ($site->name ?: $site->slug);

        // Creates the RealtimeApp (status: provisioning) and dispatches the
        // queued KV publish; credentials exist immediately so the env contract
        // is known up front and the binding is configured right away.
        $app = app(CreateRealtimeApp::class)->handle($user, $org, ['name' => $name, 'tier' => $tier]);

        return $this->persist($site, 'broadcasting', [
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $app->name,
            'target_type' => 'realtime_app',
            'target_id' => (string) $app->id,
            'injected_env' => $this->managedBroadcastingEnv($app),
            'config' => ['kind' => 'managed', 'tier' => $app->tierSlug()],
        ]);
    }

    /**
     * Stand Laravel Reverb up ON the site's own server, with nothing for the
     * operator to type. dply mints the app credentials, allocates a free
     * loopback port and records it on the site meta — which is what flips
     * {@see \App\Models\Site::shouldProxyReverbInWebserver()}, so every
     * webserver builder already emits the wss + Pusher-HTTP-API proxy for it
     * with no new config code. The composer require, the supervisor program and
     * the vhost reload are driven by {@see \App\Jobs\SetUpSiteReverbJob},
     * dispatched once this binding saves.
     *
     * Re-running keeps the existing credentials and port: rotating them on a
     * reconfigure would break every connected client for no reason.
     */
    private function attachSelfHostedReverb(Site $site): SiteBinding
    {
        $server = $site->server;
        if ($server === null || $server->hostCapabilities()->supportsSsh() !== true) {
            throw new InvalidArgumentException(__('Self-hosted Reverb needs a site on a dply-managed server. Bring your own credentials instead.'));
        }

        // Reverb is served through the site's own vhost, so it needs a hostname
        // to be reachable at — and the browser needs one to dial wss://.
        $host = strtolower(trim((string) ($site->primaryDomain()?->hostname ?: $site->testingHostname())));
        if ($host === '') {
            throw new InvalidArgumentException(__('Add a domain to this site first — Reverb serves websockets on it.'));
        }

        $existing = $site->bindings->firstWhere('type', 'broadcasting');
        $prior = ($existing instanceof SiteBinding && (string) $existing->target_type === 'broadcasting_self_hosted')
            ? (array) $existing->injected_env
            : [];

        $port = (int) ($prior['REVERB_SERVER_PORT'] ?? 0) ?: $this->freeReverbPort($site);

        // The two keys the webserver builders read. Written here so connecting
        // the resource is the whole setup — no second trip to the Runtime tab.
        $meta = is_array($site->meta) ? $site->meta : [];
        $reverbMeta = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
        $reverbMeta['port'] = $port;
        $reverbMeta['ws_path'] = trim((string) ($reverbMeta['ws_path'] ?? '')) ?: '/app';
        $meta['laravel_reverb'] = $reverbMeta;
        $site->forceFill(['meta' => $meta])->save();

        $env = [
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_ID' => (string) ($prior['REVERB_APP_ID'] ?? random_int(100000, 999999)),
            'REVERB_APP_KEY' => (string) ($prior['REVERB_APP_KEY'] ?? Str::lower(Str::random(20))),
            'REVERB_APP_SECRET' => (string) ($prior['REVERB_APP_SECRET'] ?? Str::lower(Str::random(20))),
            // What the app and the browser dial: the public vhost, which proxies
            // both the websocket path and /apps/* down to the loopback port.
            'REVERB_HOST' => $host,
            'REVERB_PORT' => '443',
            'REVERB_SCHEME' => 'https',
            // What the daemon binds. Loopback, never 0.0.0.0 — the raw port must
            // not be reachable from outside; everything arrives via the vhost.
            'REVERB_SERVER_HOST' => '127.0.0.1',
            'REVERB_SERVER_PORT' => (string) $port,
        ];

        return $this->persist($site, 'broadcasting', [
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'reverb',
            'target_type' => 'broadcasting_self_hosted',
            'target_id' => (string) $server->id,
            'injected_env' => [...$env, ...$this->broadcastingViteMirror($env)],
            'config' => ['kind' => 'self_hosted', 'driver' => 'reverb', 'port' => $port],
        ]);
    }

    /**
     * First unused loopback port for Reverb on this site's server. Two sites
     * both defaulting to 8080 would fight over the socket and the loser
     * crash-loops under Supervisor with nothing in the UI saying why, so the
     * port is allocated rather than defaulted. Octane ports count as taken —
     * they live on the same loopback.
     */
    private function freeReverbPort(Site $site): int
    {
        [$from, $to] = self::REVERB_PORT_RANGE;

        $taken = [];
        $siblings = Site::query()
            ->where('server_id', $site->server_id)
            ->whereKeyNot($site->getKey())
            ->get(['id', 'meta', 'octane_port']);

        foreach ($siblings as $sibling) {
            foreach ([(int) (data_get($sibling->meta, 'laravel_reverb.port') ?? 0), (int) ($sibling->octane_port ?? 0)] as $used) {
                if ($used > 0) {
                    $taken[$used] = true;
                }
            }
        }

        for ($port = $from; $port <= $to; $port++) {
            if (! isset($taken[$port])) {
                return $port;
            }
        }

        throw new RuntimeException(__('No free websocket port left on this server.'));
    }

    /**
     * @param  array<string, mixed> $params
     */
    private function attachByoBroadcasting(Site $site, array $params): SiteBinding
    {
        $driver = strtolower(trim((string) ($params['driver'] ?? 'pusher')));
        if (! in_array($driver, self::BROADCAST_BYO_DRIVERS, true)) {
            throw new InvalidArgumentException(__('Unsupported broadcasting driver.'));
        }

        $env = $this->byoBroadcastingEnv($driver, $params);

        return $this->persist($site, 'broadcasting', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'broadcasting-'.$driver,
            'target_type' => 'broadcasting_byo',
            'target_id' => null,
            'injected_env' => $env,
            'config' => ['kind' => 'byo', 'driver' => $driver],
        ]);
    }

    /**
     * The env the dply-managed relay (Pusher protocol) injects: server vars plus
     * the VITE_ mirror for Echo. The signing secret is server-only — never
     * mirrored to a VITE_ (client) var.
     *
     * @return array<string, string>
     */
    private function managedBroadcastingEnv(RealtimeApp $app): array
    {
        $env = [
            'BROADCAST_CONNECTION' => 'pusher',
            'PUSHER_APP_ID' => (string) $app->id,
            'PUSHER_APP_KEY' => (string) $app->app_key,
            'PUSHER_APP_SECRET' => (string) $app->app_secret,
            'PUSHER_HOST' => $app->host(),
            'PUSHER_PORT' => '443',
            'PUSHER_SCHEME' => 'https',
            // pusher-js requires a non-empty cluster even when host is set; it's
            // ignored once PUSHER_HOST points at the relay.
            'PUSHER_APP_CLUSTER' => 'mt1',
        ];

        return [...$env, ...$this->broadcastingViteMirror($env)];
    }

    /**
     * Build the BYO env for a driver. pusher/reverb carry full connection vars;
     * ably a single key; log/null just flip BROADCAST_CONNECTION.
     *
     * @param  array<string, mixed> $params
     * @return array<string, string>
     */
    private function byoBroadcastingEnv(string $driver, array $params): array
    {
        $p = fn (string $k): string => trim((string) ($params[$k] ?? ''));

        $env = match ($driver) {
            'pusher' => $this->validatedPusherEnv($p),
            'reverb' => $this->validatedReverbEnv($p),
            'ably' => $this->validatedAblyEnv($p),
            'log' => ['BROADCAST_CONNECTION' => 'log'],
            'null' => ['BROADCAST_CONNECTION' => 'null'],
            default => throw new InvalidArgumentException(__('Unsupported broadcasting driver.')),
        };

        return [...$env, ...$this->broadcastingViteMirror($env)];
    }

    /**
     * @param  callable(string): string  $p
     * @return array<string, string>
     */
    private function validatedPusherEnv(callable $p): array
    {
        if ($p('pusher_app_key') === '' || $p('pusher_app_secret') === '' || $p('pusher_app_id') === '') {
            throw new InvalidArgumentException(__('Pusher app id, key, and secret are required.'));
        }

        return array_filter([
            'BROADCAST_CONNECTION' => 'pusher',
            'PUSHER_APP_ID' => $p('pusher_app_id'),
            'PUSHER_APP_KEY' => $p('pusher_app_key'),
            'PUSHER_APP_SECRET' => $p('pusher_app_secret'),
            'PUSHER_HOST' => $p('pusher_host') ?: null,
            'PUSHER_PORT' => $p('pusher_port') ?: null,
            'PUSHER_SCHEME' => $p('pusher_scheme') ?: null,
            'PUSHER_APP_CLUSTER' => $p('pusher_cluster') ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  callable(string): string  $p
     * @return array<string, string>
     */
    private function validatedReverbEnv(callable $p): array
    {
        if ($p('reverb_app_key') === '' || $p('reverb_app_secret') === '' || $p('reverb_app_id') === '' || $p('reverb_host') === '') {
            throw new InvalidArgumentException(__('Reverb app id, key, secret, and host are required.'));
        }

        return array_filter([
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_ID' => $p('reverb_app_id'),
            'REVERB_APP_KEY' => $p('reverb_app_key'),
            'REVERB_APP_SECRET' => $p('reverb_app_secret'),
            'REVERB_HOST' => $p('reverb_host'),
            'REVERB_PORT' => $p('reverb_port') ?: null,
            'REVERB_SCHEME' => $p('reverb_scheme') ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  callable(string): string  $p
     * @return array<string, string>
     */
    private function validatedAblyEnv(callable $p): array
    {
        if ($p('ably_key') === '') {
            throw new InvalidArgumentException(__('Ably key is required.'));
        }

        return [
            'BROADCAST_CONNECTION' => 'ably',
            'ABLY_KEY' => $p('ably_key'),
        ];
    }

    /**
     * Mirror the PUSHER_ and REVERB_ connection vars to VITE_ so Laravel Echo
     * (the browser client, which reads import.meta.env at build time) connects
     * without the operator hand-adding them. The signing secret is excluded —
     * it must never reach client-side bundles.
     *
     * @param  array<string, mixed> $env
     * @return array<string, string>
     */
    private function broadcastingViteMirror(array $env): array
    {
        $mirror = [];
        foreach ($env as $key => $value) {
            if ($key === 'PUSHER_APP_SECRET' || $key === 'REVERB_APP_SECRET') {
                continue;
            }
            // REVERB_SERVER_* is the daemon's own bind address (loopback).
            // Mirroring it would tell the browser to dial 127.0.0.1.
            if (str_starts_with((string) $key, 'REVERB_SERVER_')) {
                continue;
            }
            if (str_starts_with($key, 'PUSHER_') || str_starts_with($key, 'REVERB_')) {
                $mirror['VITE_'.$key] = $value;
            }
        }

        return $mirror;
    }

    /**
     * Tear down a managed broadcasting app when its last site detaches: pull the
     * KV record (revokes connect/publish immediately) and mark the app inactive
     * so billing stops. BYO bindings own no external infra, so this is a no-op
     * for them. Shared apps with other bindings are left running.
     */
    private function teardownBroadcasting(SiteBinding $binding): void
    {
        if ((string) $binding->target_type === 'broadcasting_self_hosted') {
            $this->teardownSelfHostedReverb($binding);

            return;
        }

        if ((string) $binding->target_type !== 'realtime_app') {
            return;
        }

        $appId = (string) ($binding->target_id ?? '');
        if ($appId === '') {
            return;
        }

        $stillBound = SiteBinding::query()
            ->where('type', 'broadcasting')
            ->where('target_type', 'realtime_app')
            ->where('target_id', $appId)
            ->whereKeyNot($binding->id)
            ->exists();

        if ($stillBound) {
            return; // another site still uses this app — keep it running.
        }

        $app = RealtimeApp::query()->find($appId);
        if (! $app instanceof RealtimeApp) {
            return;
        }

        try {
            RealtimeBackendFactory::make()->deprovision($app);
        } catch (\Throwable) {
            // Best-effort: a relay error must not block detaching the binding.
        }

        $app->forceFill(['status' => RealtimeApp::STATUS_PAUSED])->save();
    }

    /**
     * Detaching self-hosted Reverb has to take the daemon with it. The env goes
     * with the binding, so a program left behind would keep restarting against
     * credentials the app no longer has — a crash-loop nobody would think to
     * look for. The site meta port stays: it costs nothing and re-attaching
     * then reuses the same port instead of drifting up the range.
     */
    private function teardownSelfHostedReverb(SiteBinding $binding): void
    {
        $site = $binding->site;
        if ($site === null || $site->server === null) {
            return;
        }

        $programs = SupervisorProgram::query()
            ->where('site_id', $site->id)
            ->where('program_type', 'reverb')
            ->get();

        if ($programs->isEmpty()) {
            return;
        }

        $provisioner = app(SupervisorProvisioner::class);

        foreach ($programs as $program) {
            try {
                $provisioner->deleteConfigFile($site->server, (string) $program->id);
            } catch (\Throwable) {
                // Best-effort: an unreachable box must not block the detach.
            }
            $program->delete();
        }
    }
}
