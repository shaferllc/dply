<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Jobs\Concerns\WritesConsoleAction;
use App\Jobs\Concerns\WritesPerSiteWebserverConfigs;
use App\Models\Server;
use App\Models\ServerWebserverAuditEvent;
use App\Models\Site;
use App\Services\RemoteCli\RiskLevel;
use App\Services\Servers\TraefikDashboardExposure;
use App\Services\SshConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Remove the L7 edge proxy (Traefik / HAProxy / Envoy) and restore the webserver
 * that was active before the edge proxy was added (`meta.edge_proxy_previous_webserver`).
 *
 * Caddy backends on high ports are torn down; site configs are regenerated for
 * the previous engine on :80; the edge proxy unit is stopped and disabled.
 */
class RemoveEdgeProxyJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use PrivilegedRemoteFileWrites;
    use Queueable;
    use SerializesModels;
    use WritesConsoleAction;
    use WritesPerSiteWebserverConfigs;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $serverId,
        public ?string $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'edge_proxy_remove_'.$this->serverId;
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    protected function consoleSubject(): Model
    {
        return Server::query()->findOrFail($this->serverId);
    }

    protected function consoleKind(): string
    {
        return 'edge_proxy';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            return;
        }

        $edgeProxy = $server->edgeProxy();
        if ($edgeProxy === null) {
            $this->beginConsoleAction()->info('No edge proxy is active on this server.');
            $this->completeConsoleAction();

            return;
        }

        $emitter = $this->beginConsoleAction();
        $startedAt = microtime(true);
        $previousWebserver = $this->resolveEdgeProxyPreviousWebserver($server);

        try {
            $sites = Site::query()
                ->where('server_id', $server->id)
                ->with(['domains', 'domainAliases', 'tenantDomains', 'redirects', 'basicAuthUsers', 'server'])
                ->get();

            $emitter->info(sprintf('[cleanup]  removing %s edge-proxy artifacts', $edgeProxy));
            $this->executeStageRemoveEdgeArtifacts($server, $edgeProxy, $sites);

            $emitter->info(sprintf('[restore]  rewriting %d site config(s) for %s on :80', $sites->count(), $previousWebserver));
            $this->executeStageRestoreSites($server, $sites, $previousWebserver);

            $emitter->info(sprintf('[validate] %s config check', $previousWebserver));
            $this->validateWebserverConfig($server, $previousWebserver);

            $emitter->info(sprintf('[cutover]  stop %s, bind %s to :80', $edgeProxy, $previousWebserver));
            $this->executeStageCutover($server, $edgeProxy, $previousWebserver);

            $meta = is_array($server->meta) ? $server->meta : [];
            unset($meta['edge_proxy'], $meta['edge_proxy_previous_webserver']);
            $meta['webserver'] = $previousWebserver;
            $server->update(['meta' => $meta]);

            SyncServerSystemdServicesJob::dispatch($server->id);

            $emitter->info('Done.');
            $this->completeConsoleAction();
            $this->recordAudit($server, $edgeProxy, ServerWebserverAuditEvent::ACTION_EDGE_PROXY_REMOVED, [
                'edge_proxy' => $edgeProxy,
                'restored_webserver' => $previousWebserver,
                'sites_affected' => $sites->count(),
            ], $startedAt, ServerWebserverAuditEvent::RESULT_SUCCESS);
        } catch (\Throwable $e) {
            $emitter->error('Edge-proxy remove failed: '.$e->getMessage());
            $this->failConsoleAction($e->getMessage());
            $this->recordAudit($server, $edgeProxy, ServerWebserverAuditEvent::ACTION_EDGE_PROXY_FAILED, [
                'edge_proxy' => $edgeProxy,
                'restored_webserver' => $previousWebserver,
                'reason' => $e->getMessage(),
            ], $startedAt);
        }
    }

    public function failed(\Throwable $e): void
    {
        app(UniqueLock::class)->release($this);
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    protected function executeStageRemoveEdgeArtifacts(Server $server, string $edgeProxy, Collection $sites): void
    {
        $ssh = new SshConnection($server);

        foreach ($sites as $site) {
            $basename = $this->basenameForSite($site);
            $ssh->exec($this->privilegedCommand(
                $server,
                'rm -f /etc/caddy/sites-enabled/'.escapeshellarg($basename).'-backend.caddy'
            ), 15);
        }

        if ($edgeProxy === 'traefik') {
            foreach ($sites as $site) {
                $basename = $this->basenameForSite($site);
                $ssh->exec($this->privilegedCommand(
                    $server,
                    'rm -f /etc/traefik/dynamic/'.escapeshellarg($basename.'.yml')
                ), 15);
            }
            $ssh->exec($this->privilegedCommand(
                $server,
                'rm -f '.escapeshellarg(TraefikDashboardExposure::MANAGED_PATH)
            ), 15);
        }

        if ($edgeProxy === 'haproxy') {
            $ssh->exec($this->privilegedCommand(
                $server,
                sprintf(
                    '[ -f %1$s.dply-bak ] && cp %1$s.dply-bak %1$s || true',
                    escapeshellarg('/etc/haproxy/haproxy.cfg'),
                ),
            ), 15);
        }

        if ($edgeProxy === 'envoy') {
            $ssh->exec($this->privilegedCommand(
                $server,
                sprintf(
                    '[ -f %1$s.dply-bak ] && cp %1$s.dply-bak %1$s || true',
                    escapeshellarg('/etc/envoy/envoy.yaml'),
                ),
            ), 15);
        }
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    protected function executeStageRestoreSites(Server $server, Collection $sites, string $previousWebserver): void
    {
        if ($sites->isEmpty()) {
            return;
        }

        $ssh = new SshConnection($server);
        $this->ensureTargetConfigDirs($server, $ssh, $previousWebserver);

        foreach ($sites as $site) {
            $basename = $this->basenameForSite($site);

            if ($previousWebserver !== 'caddy') {
                $ssh->exec($this->privilegedCommand(
                    $server,
                    'rm -f /etc/caddy/sites-enabled/'.escapeshellarg($basename).'.caddy'
                ), 15);
            }

            $config = $this->buildSiteConfigFor($site, $previousWebserver, listenPort: null);
            $path = $this->siteConfigPathFor($site, $previousWebserver);
            $this->writeRemoteFile($server, $ssh, $path, $config);
            $this->ensureSiteEnabled($server, $ssh, $site, $previousWebserver);

            if ($previousWebserver === 'openlitespeed') {
                $repo = rtrim($site->effectiveRepositoryPath(), '/');
                $ssh->exec($this->privilegedCommand($server, 'mkdir -p '.escapeshellarg($repo.'/logs')), 15);
            }
        }

        if ($previousWebserver === 'openlitespeed') {
            $this->writeOlsHttpdConfig($server, $ssh, $sites, listenPort: 80);
        }

        if ($previousWebserver === 'caddy') {
            foreach ($sites as $site) {
                $basename = $this->basenameForSite($site);
                $ssh->exec($this->privilegedCommand(
                    $server,
                    'rm -f /etc/caddy/sites-enabled/'.escapeshellarg($basename).'-backend.caddy'
                ), 15);
            }
            $this->ensureCaddyRuntimeOwnership($server, $ssh);
        }
    }

    protected function executeStageCutover(Server $server, string $edgeProxy, string $previousWebserver): void
    {
        $ssh = new SshConnection($server);
        $edgeUnit = $this->systemdUnitForWebserver($edgeProxy);
        $webserverUnit = $this->systemdUnitForWebserver($previousWebserver);

        if ($edgeUnit !== null) {
            $ssh->exec(
                $this->privilegedCommand($server, sprintf('systemctl stop %s 2>/dev/null || true', escapeshellarg($edgeUnit))),
                30,
            );
            $this->waitForPortFree($server, $ssh, 80);
        }

        if ($previousWebserver === 'caddy') {
            if ($webserverUnit !== null) {
                $this->ensureCaddyRuntimeOwnership($server, $ssh);
                $cmd = '(systemctl is-active --quiet caddy && systemctl reload caddy) || systemctl enable --now caddy';
                $out = $ssh->exec($this->privilegedCommand($server, $cmd.' 2>&1'), 60);
                $exit = $ssh->lastExecExitCode();
                if ($exit !== null && $exit !== 0) {
                    throw new \RuntimeException(sprintf(
                        'Failed to reload Caddy on :80 (exit %d): %s',
                        $exit,
                        trim(substr((string) $out, -500)),
                    ));
                }
            }
        } elseif ($webserverUnit !== null) {
            $caddyUnit = $this->systemdUnitForWebserver('caddy');
            if ($caddyUnit !== null) {
                $ssh->exec($this->privilegedCommand(
                    $server,
                    sprintf('systemctl stop %s 2>/dev/null || true; systemctl disable %s 2>/dev/null || true', escapeshellarg($caddyUnit), escapeshellarg($caddyUnit)),
                ), 30);
            }

            $cmd = sprintf(
                'systemctl enable %1$s 2>/dev/null || true; systemctl restart %1$s 2>&1; systemctl is-active %1$s',
                escapeshellarg($webserverUnit),
            );
            $out = $ssh->exec($this->privilegedCommand($server, $cmd), 60);
            $exit = $ssh->lastExecExitCode();
            if ($exit !== null && $exit !== 0) {
                throw new \RuntimeException(sprintf(
                    'Failed to start %s on :80 (exit %d): %s',
                    $previousWebserver,
                    $exit,
                    trim(substr((string) $out, -500)),
                ));
            }
        }

        if ($edgeUnit !== null) {
            $ssh->exec(
                $this->privilegedCommand($server, sprintf('systemctl disable %s 2>/dev/null || true', escapeshellarg($edgeUnit))),
                30,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordAudit(Server $server, string $edgeProxy, string $action, array $payload, float $startedAt, ?string $resultStatus = null): void
    {
        ServerWebserverAuditEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $this->userId,
            'action' => $action,
            'risk' => RiskLevel::MutatingRecoverable->value,
            'transport' => ServerWebserverAuditEvent::TRANSPORT_WEB,
            'summary' => __('Edge proxy :action: :target', [
                'action' => str_contains($action, 'failed') ? 'remove failed' : 'removed',
                'target' => $edgeProxy,
            ]),
            'payload' => $payload,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'result_status' => $resultStatus ?? ServerWebserverAuditEvent::RESULT_FAILURE,
        ]);
    }
}
