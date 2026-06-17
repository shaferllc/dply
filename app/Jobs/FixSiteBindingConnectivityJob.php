<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\ServerFirewallRule;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Support\Servers\CacheServiceNetworkExposure;
use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Self-service fix for an UNREACHABLE managed-resource binding. From the site's
 * server (the consumer) it makes the backend reachable:
 *
 *   1. (optional) RE-POINT the binding at the correct backend — the common case
 *      is a binding aimed at a server that doesn't actually serve the resource.
 *   2. Enable REMOTE ACCESS on the backend (bind to private interface; pg_hba for
 *      Postgres) — reusing the same primitives as worker-pool expose.
 *   3. Open the FIREWALL for the consumer's private /32 on the resource port.
 *   4. RE-PROBE so the badge flips to reachable (or shows what's still wrong).
 *
 * Does NOT auto-attach Hetzner networks — if the servers aren't on a shared
 * private network it warns and stops (a deliberate topology change).
 */
class FixSiteBindingConnectivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
        public string $bindingId,
        public ?string $repointTargetId = null,
        public ?string $userId = null,
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(
        ExecuteRemoteTaskOnServer $exec,
        ServerFirewallProvisioner $firewall,
        CacheServiceNetworkExposure $cacheExposure,
    ): void {
        $site = Site::query()->with('server')->find($this->siteId);
        $binding = SiteBinding::query()->find($this->bindingId);
        $action = ConsoleAction::query()->find($this->consoleActionId);
        if ($site === null || $binding === null || $action === null || $site->server === null) {
            return;
        }
        $consumer = $site->server;

        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emit = new ConsoleEmitter($this->consoleActionId);

        try {
            $emit->step('fix', sprintf(
                'Fixing connectivity for the %s binding on site “%s” (consumer server: %s).',
                $binding->type, $site->name, $consumer->name,
            ));

            // 1. Re-point if requested.
            $target = $this->repointTargetId !== null
                ? $this->repoint($binding, $emit)
                : $this->resolveTarget($binding);

            if ($target === null || ! $target->server instanceof Server) {
                $emit->error('Could not resolve a backend for this binding — pick one to re-point to.', 'fix');
                $this->finish(false);

                return;
            }
            $backend = $target->server;
            $emit->info(sprintf(
                'Backend resolved: %s “%s” on server %s.',
                $target instanceof ServerCacheService ? 'cache' : 'database',
                $target->name ?: $target->engine,
                $backend->name,
            ), 'fix');

            // Same-box short-circuit: when the resource lives on the SAME server as
            // the site, there is no network to cross — it must be reached over
            // loopback. A stored private-IP host (e.g. 10.x saved at provision) is
            // not bound by the engine and reads as unreachable. Repoint to
            // 127.0.0.1 and re-probe; no private network, no firewall, no
            // provider-specific network attach. This is the common "local database"
            // case and must never fall through to the cross-network path below.
            if ((string) $backend->id === (string) $consumer->id) {
                $hostKey = $binding->type === 'database' ? 'DB_HOST' : 'REDIS_HOST';
                $env = $binding->connectionEnv();
                $env[$hostKey] = '127.0.0.1';
                $binding->forceFill(['injected_env' => $env, 'last_error' => null])->save();

                $emit->success(sprintf(
                    '%s is co-located with %s — repointed %s to 127.0.0.1 (loopback). Re-probing…',
                    $target->name ?: $target->engine, $consumer->name, $hostKey,
                ), 'fix');

                $this->reprobe($site, $binding);
                $this->finish(true);

                return;
            }

            // 2. Ensure the consumer is on the backend's private network.
            $backendNet = $this->resolveNetwork($backend);
            $emit->info($backendNet !== null
                ? sprintf('Backend private network: “%s” (Hetzner #%s, range %s).', $backendNet->name, $backendNet->hetznerNetworkId() ?: '—', $backendNet->ip_range)
                : sprintf('Backend %s has no resolvable private network.', $backend->name), 'fix');
            $emit->info(sprintf(
                'Consumer %s: private_network_id=%s, hetzner_network_id=%s, private_ip=%s.',
                $consumer->name,
                $consumer->private_network_id ?: '—',
                $consumer->hetzner_network_id ?: '—',
                $consumer->private_ip_address ?: '—',
            ), 'fix');

            $onSameNet = $backendNet !== null && (
                (string) $consumer->private_network_id === (string) $backendNet->id
                || ($consumer->hetzner_network_id !== null && (string) $consumer->hetzner_network_id === (string) $backendNet->hetznerNetworkId())
            );
            $emit->info($onSameNet
                ? 'Consumer and backend share a private network — proceeding to open access.'
                : 'Consumer and backend are NOT on a shared private network.', 'fix');

            // Cross-box without a shared private network: do NOT auto-attach one.
            // Joining a server to a private network is a deliberate topology change,
            // it's provider-specific (DigitalOcean VPC vs Hetzner network vs …), and
            // silently bridging two boxes that the operator didn't put on the same
            // network is exactly the isolation we must preserve. Warn and stop —
            // private connectivity requires an explicit shared network, otherwise the
            // resource has to be reached publicly.
            if (! $onSameNet) {
                $emit->warn(sprintf(
                    '%s and %s aren’t on a shared private network, so %s can’t reach it over a private IP. dply won’t auto-attach a network — put both servers on the same private network from the network page, or expose the resource publicly. If the database actually lives on %s, re-point this binding to it instead (it’ll then use loopback).',
                    $consumer->name, $backend->name, $consumer->name, $consumer->name,
                ), 'fix');
                $this->finish(false);

                return;
            }

            $consumerPriv = trim((string) $consumer->private_ip_address);
            $backendPriv = trim((string) $backend->private_ip_address);
            if ($consumerPriv === '' || $backendPriv === '') {
                $emit->warn(sprintf('%s or %s has no private IP yet — wait for the network attach to finish (~30s), then re-run.', $consumer->name, $backend->name), 'fix');
                $this->finish(false);

                return;
            }
            $cidr = $consumerPriv.'/32';
            $emit->info(sprintf(
                'Consumer private IP %s, backend private IP %s. Will open %s on the backend.',
                $consumerPriv, $backendPriv, $cidr,
            ), 'fix');

            // 3. Enable remote access + firewall for the consumer's /32.
            [$port, $label] = $this->exposeBackend($target, $backend, $cidr, $exec, $firewall, $cacheExposure, $emit);

            // 4. Re-probe (its own console action so the badge re-evaluates).
            $emit->step('fix', sprintf('Re-testing %s → %s:%d …', $consumer->name, $backendPriv, $port));
            $this->reprobe($site, $binding);

            $emit->success(sprintf('Exposed %s on %s to %s and re-probing — the badge updates shortly.', $label, $backend->name, $cidr), 'fix');
            $this->finish(true);
        } catch (\Throwable $e) {
            $emit->error('Fix failed: '.$e->getMessage(), 'fix');
            $this->finish(false);

            throw $e;
        }
    }

    private function finish(bool $ok): void
    {
        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => $ok ? ConsoleAction::STATUS_COMPLETED : ConsoleAction::STATUS_FAILED,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The private network a server belongs to (by FK, else by membership). */
    private function resolveNetwork(Server $server): ?PrivateNetwork
    {
        if ($server->private_network_id) {
            $net = PrivateNetwork::query()->find($server->private_network_id);
            if ($net instanceof PrivateNetwork) {
                return $net;
            }
        }

        return PrivateNetwork::query()->whereHas('servers', fn ($q) => $q->whereKey($server->id))->first();
    }

    /** Queue a fresh connectivity probe so the binding's reachable badge re-evaluates. */
    private function reprobe(Site $site, SiteBinding $binding): void
    {
        $consumer = $site->server;
        if ($consumer === null) {
            return;
        }

        $probe = ConsoleAction::query()->create([
            'subject_type' => $consumer->getMorphClass(),
            'subject_id' => $consumer->getKey(),
            'kind' => 'binding_validate',
            'status' => ConsoleAction::STATUS_QUEUED,
            'user_id' => $this->userId,
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
        ValidateBindingConnectivityJob::dispatch((string) $probe->id, (string) $site->id, (string) $binding->id);
    }

    private function resolveTarget(SiteBinding $binding): ServerDatabase|ServerCacheService|null
    {
        return match ($binding->target_type) {
            'server_database' => ServerDatabase::query()->with('server')->find($binding->target_id),
            'server_cache_service' => ServerCacheService::query()->with('server')->find($binding->target_id),
            default => null,
        };
    }

    /**
     * Re-point the binding at a new backend of the same type, rewriting its
     * injected host/port env so it targets the correct server's private IP.
     */
    private function repoint(SiteBinding $binding, ConsoleEmitter $emit): ServerDatabase|ServerCacheService|null
    {
        $target = match ($binding->type) {
            'database' => ServerDatabase::query()->with('server')->find($this->repointTargetId),
            'redis' => ServerCacheService::query()->with('server')->find($this->repointTargetId),
            default => null,
        };
        if ($target === null || ! $target->server instanceof Server) {
            return null;
        }

        $host = trim((string) $target->server->private_ip_address) ?: (string) $target->server->ip_address;
        $port = (int) ($target->port ?? 0);

        $env = $binding->connectionEnv();
        [$hostKey, $portKey] = $binding->type === 'database' ? ['DB_HOST', 'DB_PORT'] : ['REDIS_HOST', 'REDIS_PORT'];
        $env[$hostKey] = $host;
        if ($port > 0) {
            $env[$portKey] = (string) $port;
        }

        $binding->forceFill([
            'target_id' => (string) $target->id,
            'name' => $target->name ?? $binding->name,
            'injected_env' => $env,
        ])->save();

        $emit->info(sprintf('Re-pointed binding to %s (%s:%d).', $target->server->name, $host, $port), 'fix');

        return $target;
    }

    /**
     * Enable remote access on the backend resource + open the firewall /32.
     *
     * @return array{0: int, 1: string} [port, human label]
     */
    private function exposeBackend(
        ServerDatabase|ServerCacheService $target,
        Server $backend,
        string $cidr,
        ExecuteRemoteTaskOnServer $exec,
        ServerFirewallProvisioner $firewall,
        CacheServiceNetworkExposure $cacheExposure,
        ConsoleEmitter $emit,
    ): array {
        if ($target instanceof ServerCacheService) {
            $emit->step('fix', sprintf('Enabling remote access on the %s cache on %s…', $target->engine, $backend->name));
            $cacheExposure->expose($backend, $target, $cidr, $this->userId);
            $port = (int) $target->port ?: ServerCacheService::defaultPortFor((string) $target->engine);
            $emit->info(sprintf('Cache port resolved to %d.', $port), 'fix');
            $this->ensureFirewallRule($backend, $port, $cidr, 'cache:'.$target->engine, $firewall, $emit);

            return [$port, ucfirst((string) $target->engine)];
        }

        // The tracked row sometimes has no port (older provisions / detected
        // engines) — fall back to the engine default so the firewall rule below
        // is actually created instead of silently skipped (port 0 → no rule).
        $port = (int) ($target->port ?? 0) ?: DatabaseEngineInstallScripts::defaultPortFor((string) $target->engine);
        $emit->info(sprintf('Database engine %s, port resolved to %d.', $target->engine, $port), 'fix');

        $emit->step('fix', sprintf('Running remote-access script for %s on %s…', $target->engine, $backend->name));
        $script = DatabaseEngineInstallScripts::enableRemoteAccessScript((string) $target->engine, '0.0.0.0/0');
        $output = $exec->runInlineBash($backend, 'binding-fix:db-expose:'.$target->engine, $script, timeoutSeconds: 120, asRoot: true);
        // runInlineBash never throws on a non-zero exit, so surface it ourselves.
        $tail = trim($output->buffer);
        $tail = $tail === '' ? '(no output)' : mb_substr($tail, -400);
        if ((int) ($output->exitCode ?? 0) !== 0) {
            $emit->warn(sprintf('Remote-access script exited %d on %s. Output tail: %s', (int) $output->exitCode, $backend->name, $tail), 'fix');
        } else {
            $emit->success(sprintf('Remote-access script applied on %s. Output tail: %s', $backend->name, $tail), 'fix');
        }

        // NB: server_databases has no `port` column — the port is derived from
        // the engine (defaultPortFor), not stored on the row. Persist only
        // remote_access; use the resolved $port purely for the firewall rule.
        $target->forceFill(['remote_access' => true])->save();
        $this->ensureFirewallRule($backend, $port, $cidr, 'db:'.$target->engine, $firewall, $emit);

        return [$port, strtoupper((string) $target->engine)];
    }

    private function ensureFirewallRule(Server $backend, int $port, string $cidr, string $label, ServerFirewallProvisioner $firewall, ConsoleEmitter $emit): void
    {
        if ($port < 1) {
            $emit->warn(sprintf('No valid port for %s — skipping firewall rule. The connection cannot open without a port.', $label), 'fix');

            return;
        }
        if (ServerFirewallRule::query()->where('server_id', $backend->id)->where('port', $port)->where('source', $cidr)->exists()) {
            $emit->info(sprintf('Firewall rule already exists: allow %s → %d/tcp on %s. Re-applying to the host…', $cidr, $port, $backend->name), 'fix');
            $existing = ServerFirewallRule::query()->where('server_id', $backend->id)->where('port', $port)->where('source', $cidr)->first();
            if ($existing) {
                $this->applyAndReport($backend, $existing, $firewall, $emit);
            }

            return;
        }

        $emit->step('fix', sprintf('Creating firewall rule: allow %s → %d/tcp on %s…', $cidr, $port, $backend->name));
        $rule = ServerFirewallRule::query()->create([
            'server_id' => $backend->id,
            'name' => 'Binding fix '.$label.' '.$cidr,
            'port' => $port,
            'protocol' => 'tcp',
            'source' => $cidr,
            'action' => 'allow',
            'enabled' => true,
            'sort_order' => (int) (ServerFirewallRule::query()->where('server_id', $backend->id)->max('sort_order') ?? 0) + 1,
            'tags' => ['dply-binding-fix'],
        ]);

        $this->applyAndReport($backend, $rule, $firewall, $emit);
    }

    /** Push a single rule to the host's UFW and report success/failure to the console. */
    private function applyAndReport(Server $backend, ServerFirewallRule $rule, ServerFirewallProvisioner $firewall, ConsoleEmitter $emit): void
    {
        try {
            $firewall->applyRule($backend, $rule);
            $emit->success(sprintf('Firewall applied on %s: allow %s → %d/tcp.', $backend->name, $rule->source, $rule->port), 'fix');
        } catch (\Throwable $e) {
            $emit->error(sprintf('Failed to apply the firewall rule on %s: %s', $backend->name, $e->getMessage()), 'fix');
        }
    }
}
