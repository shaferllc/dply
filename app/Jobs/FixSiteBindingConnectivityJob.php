<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
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

            // 2. Shared-network check — we don't auto-attach networks.
            $consumerPriv = trim((string) $consumer->private_ip_address);
            $backendPriv = trim((string) $backend->private_ip_address);
            if ($consumerPriv === '' || $backendPriv === '') {
                $emit->warn(sprintf('%s or %s has no private IP — attach both to a shared private network first, then re-run.', $consumer->name, $backend->name), 'fix');
                $this->finish(false);

                return;
            }
            $cidr = $consumerPriv.'/32';

            // 3. Enable remote access + firewall for the consumer's /32.
            [$port, $label] = $this->exposeBackend($target, $backend, $cidr, $exec, $firewall, $cacheExposure, $emit);

            // 4. Re-probe (its own console action so the badge re-evaluates).
            $emit->step('fix', sprintf('Re-testing %s → %s:%d …', $consumer->name, $backendPriv, $port));
            $probe = ConsoleAction::query()->create([
                'subject_type' => $consumer->getMorphClass(),
                'subject_id' => $consumer->getKey(),
                'kind' => 'binding_validate',
                'status' => ConsoleAction::STATUS_QUEUED,
                'user_id' => $this->userId,
                'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
            ]);
            ValidateBindingConnectivityJob::dispatch((string) $probe->id, (string) $site->id, (string) $binding->id);

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
     * @return array{0: int, 1: string}  [port, human label]
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
            $emit->info('Enabling remote access on the cache + firewalling your app server…', 'fix');
            $cacheExposure->expose($backend, $target, $cidr, $this->userId);
            $port = (int) $target->port;
            $this->ensureFirewallRule($backend, $port, $cidr, 'cache:'.$target->engine, $firewall);

            return [$port, ucfirst((string) $target->engine)];
        }

        $emit->info('Enabling remote access on the database + firewalling your app server…', 'fix');
        $script = DatabaseEngineInstallScripts::enableRemoteAccessScript((string) $target->engine, '0.0.0.0/0');
        $exec->runInlineBash($backend, 'binding-fix:db-expose:'.$target->engine, $script, timeoutSeconds: 120, asRoot: true);
        $target->forceFill(['remote_access' => true])->save();
        $port = (int) ($target->port ?? 0);
        $this->ensureFirewallRule($backend, $port, $cidr, 'db:'.$target->engine, $firewall);

        return [$port, strtoupper((string) $target->engine)];
    }

    private function ensureFirewallRule(Server $backend, int $port, string $cidr, string $label, ServerFirewallProvisioner $firewall): void
    {
        if ($port < 1) {
            return;
        }
        if (ServerFirewallRule::query()->where('server_id', $backend->id)->where('port', $port)->where('source', $cidr)->exists()) {
            return;
        }

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

        $firewall->applyRule($backend, $rule);
    }
}
