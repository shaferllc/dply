<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Models\ServerWebserverAuditEvent;
use App\Models\Site;
use App\Services\RemoteCli\RiskLevel;
use App\Services\Sites\CaddySiteConfigBuilder;
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
 * Remove the L7 edge proxy in front of the server's webserver. The
 * recovery target is Caddy-on-:80 — when an edge proxy was added,
 * Caddy was installed as the per-site backend on high ports; removing
 * the edge proxy means transitioning Caddy from backend role back to
 * edge role. We rewrite per-site Caddy configs to bind :80, drop the
 * -backend.caddy fragments, reload Caddy, then stop + disable the edge
 * proxy.
 *
 * Side effect: `meta.webserver` is updated to 'caddy' since Caddy is
 * what's actually serving :80 after this. If the operator wants a
 * different webserver (nginx/apache/openlitespeed), they run the
 * SwitchServerWebserverJob next.
 */
class RemoveEdgeProxyJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use PrivilegedRemoteFileWrites;
    use Queueable;
    use SerializesModels;
    use WritesConsoleAction;

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
            // No edge proxy active — nothing to remove. Treat as success.
            $this->beginConsoleAction()->info('No edge proxy is active on this server.');
            $this->completeConsoleAction();

            return;
        }

        $emitter = $this->beginConsoleAction();
        $startedAt = microtime(true);

        try {
            $sites = Site::query()
                ->where('server_id', $server->id)
                ->with(['domains', 'domainAliases', 'tenantDomains', 'redirects', 'basicAuthUsers', 'server'])
                ->get();

            $emitter->info(sprintf('[restore]  rewriting %d caddy config(s) for :80', $sites->count()));
            $this->executeStageRestoreCaddy($server, $sites);

            $emitter->info('[validate] caddy validate');
            $this->executeStageValidate($server);

            $emitter->info(sprintf('[cutover]  stop %s, reload caddy on :80', $edgeProxy));
            $this->executeStageCutover($server, $edgeProxy);

            $meta = is_array($server->meta) ? $server->meta : [];
            unset($meta['edge_proxy']);
            $meta['webserver'] = 'caddy';
            $server->update(['meta' => $meta]);

            // Re-probe systemd inventory so meta.manage_units reflects the
            // post-remove state (caddy back on :80, edge proxy stopped+disabled).
            SyncServerSystemdServicesJob::dispatch($server->id);

            $emitter->info('Done.');
            $this->completeConsoleAction();
            $this->recordAudit($server, $edgeProxy, ServerWebserverAuditEvent::ACTION_EDGE_PROXY_REMOVED, [
                'edge_proxy' => $edgeProxy,
                'sites_affected' => $sites->count(),
            ], $startedAt, ServerWebserverAuditEvent::RESULT_SUCCESS);
        } catch (\Throwable $e) {
            $emitter->error('Edge-proxy remove failed: '.$e->getMessage());
            $this->failConsoleAction($e->getMessage());
            $this->recordAudit($server, $edgeProxy, ServerWebserverAuditEvent::ACTION_EDGE_PROXY_FAILED, [
                'edge_proxy' => $edgeProxy,
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
    protected function executeStageRestoreCaddy(Server $server, $sites): void
    {
        $ssh = new SshConnection($server);

        foreach ($sites as $site) {
            $basename = $this->basenameFor($site);

            // Write the :80-bound Caddy config — same builder caddy-edge uses.
            $config = app(CaddySiteConfigBuilder::class)->build($site, null);
            $this->writeRemoteFile($server, $ssh, '/etc/caddy/sites-enabled/'.$basename.'.caddy', $config);

            // Drop the -backend.caddy fragment so it doesn't double-bind on
            // reload (its high port is still defined inside).
            $ssh->exec($this->privilegedCommand(
                $server,
                'rm -f /etc/caddy/sites-enabled/'.escapeshellarg($basename).'-backend.caddy'
            ), 15);
        }
    }

    protected function executeStageValidate(Server $server): void
    {
        $ssh = new SshConnection($server);
        $out = $ssh->exec(
            $this->privilegedCommand($server, 'caddy validate --config /etc/caddy/Caddyfile 2>&1'),
            60,
        );
        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(sprintf(
                'caddy validate failed (exit %d): %s',
                $exit,
                trim(substr($out, -500)),
            ));
        }
    }

    protected function executeStageCutover(Server $server, string $edgeProxy): void
    {
        $ssh = new SshConnection($server);
        $edgeUnit = $edgeProxy === 'traefik' ? 'traefik' : 'haproxy';

        // Stop the edge proxy first so it releases :80.
        $ssh->exec(
            $this->privilegedCommand($server, sprintf('systemctl stop %s 2>/dev/null || true', escapeshellarg($edgeUnit))),
            30,
        );

        // Reload Caddy to pick up the new :80-bound configs. If Caddy isn't
        // running, enable+start.
        $cmd = '(systemctl is-active --quiet caddy && systemctl reload caddy) || systemctl enable --now caddy';
        $out = $ssh->exec($this->privilegedCommand($server, $cmd.' 2>&1'), 60);
        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(sprintf(
                'Failed to reload Caddy on :80 (exit %d): %s',
                $exit,
                trim(substr($out, -500)),
            ));
        }

        // Disable the edge proxy at boot so it doesn't auto-start and
        // collide with Caddy's :80 binding.
        $ssh->exec(
            $this->privilegedCommand($server, sprintf('systemctl disable %s 2>/dev/null || true', escapeshellarg($edgeUnit))),
            30,
        );
    }

    private function basenameFor(Site $site): string
    {
        return method_exists($site, 'webserverConfigBasename')
            ? (string) $site->webserverConfigBasename()
            : (string) $site->slug;
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
