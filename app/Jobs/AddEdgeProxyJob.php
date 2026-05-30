<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Models\ServerWebserverAuditEvent;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\RemoteCli\RiskLevel;
use App\Services\Servers\EnvoyEdgeConfigBuilder;
use App\Services\Servers\EnvoyStaticConfigOptions;
use App\Services\Servers\HAProxyEdgeConfigBuilder;
use App\Services\Servers\TraefikStaticConfigOptions;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\TraefikSiteConfigBuilder;
use App\Services\SshConnection;
use App\Support\Servers\CaddyEdgeBackendLayout;
use App\Support\Servers\CaddyRuntimeOwnership;
use App\Support\Servers\EnvoyAdminScript;
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
 * Install an L7 edge proxy (Traefik, HAProxy, or Envoy) in front of the server's
 * webserver. dply's architecture for edge proxies is:
 *
 *   client → Traefik/HAProxy on :80 → Caddy on per-site high ports → app/static
 *
 * Caddy serves as the per-site backend because Traefik/HAProxy can't natively
 * serve PHP-FPM upstreams or static files. The previously-active webserver
 * (whatever it was) is stopped and replaced by Caddy as backend. The
 * operator's chosen `meta.webserver` is preserved in meta so a future remove
 * flow knows what to restore — but while the edge proxy is active, Caddy is
 * doing the actual work.
 *
 * Staged execution mirrors SwitchServerWebserverJob:
 *
 *   1. install     — apt the edge proxy + Caddy (idempotent)
 *   2. provision   — per-site Caddy backends on ephemeral ports + edge config on :8080
 *   3. validate    — caddy validate + edge-proxy parse-check
 *   4. cutover     — stop old webserver, rewrite edge config to :80, start Caddy + edge proxy
 *   5. finalize    — persist meta.edge_proxy
 */
class AddEdgeProxyJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use PrivilegedRemoteFileWrites;
    use Queueable;
    use SerializesModels;
    use WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $serverId,
        public string $target,   // 'traefik' | 'haproxy' | 'envoy'
        public ?string $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'edge_proxy_add_'.$this->serverId;
    }

    /**
     * Short lock window. See SwitchServerWebserverJob::uniqueFor() for the
     * full rationale — the UI's hasInflightEdgeProxyAction() check is the
     * canonical guard against double-dispatch; the lock only covers the
     * dispatch race.
     */
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
        if (! in_array($this->target, ['traefik', 'haproxy', 'envoy'], true)) {
            return;
        }

        $emitter = $this->beginConsoleAction();
        $startedAt = microtime(true);
        $previousWebserver = strtolower(trim((string) ($server->meta['webserver'] ?? 'nginx')));
        $previousEdgeProxy = $server->edgeProxy();

        try {
            $emitter->info(sprintf('[install]    installing %s + caddy backend…', $this->target));
            $this->executeStageInstall($server, $emitter);

            $sites = Site::query()
                ->where('server_id', $server->id)
                ->with(['domains', 'domainAliases', 'tenantDomains', 'previewDomains', 'redirects', 'basicAuthUsers', 'server'])
                ->get();

            $emitter->info(sprintf('[provision]  writing %d caddy backend(s) + edge config', $sites->count()));
            $this->executeStageProvision($server, $sites);

            $emitter->info('[validate]   caddy validate + edge config parse-check');
            $this->executeStageValidate($server);

            $emitter->info(sprintf('[cutover]    stop %s, bind %s to :80', $previousWebserver, $this->target));
            $this->executeStageCutover($server, $sites, $previousWebserver);

            $meta = is_array($server->meta) ? $server->meta : [];
            $meta['edge_proxy'] = $this->target;
            if ($previousEdgeProxy === null) {
                $meta['edge_proxy_previous_webserver'] = $previousWebserver;
            }
            $server->update(['meta' => $meta]);

            // Re-probe systemd inventory so meta.manage_units picks up the
            // new edge-proxy unit (traefik / haproxy) + the freshly-restarted
            // caddy backend's state. See SwitchServerWebserverJob's matching
            // dispatch for the rationale.
            SyncServerSystemdServicesJob::dispatch($server->id);

            $emitter->info('Done.');
            $this->completeConsoleAction();
            $this->recordAudit($server, ServerWebserverAuditEvent::ACTION_EDGE_PROXY_ADDED, [
                'edge_proxy' => $this->target,
                'previous_webserver' => $previousWebserver,
                'previous_edge_proxy' => $previousEdgeProxy,
                'sites_affected' => $sites->count(),
            ], $startedAt, ServerWebserverAuditEvent::RESULT_SUCCESS);
        } catch (\Throwable $e) {
            $emitter->error('Edge-proxy add failed: '.$e->getMessage());
            $this->failConsoleAction($e->getMessage());
            $this->recordAudit($server, ServerWebserverAuditEvent::ACTION_EDGE_PROXY_FAILED, [
                'edge_proxy' => $this->target,
                'reason' => $e->getMessage(),
            ], $startedAt);
        }
    }

    public function failed(\Throwable $e): void
    {
        app(UniqueLock::class)->release($this);
    }

    protected function executeStageInstall(Server $server, ConsoleEmitter $emitter): void
    {
        $ssh = new SshConnection($server);
        $script = $this->installerScriptFor($this->target);
        $prelude = <<<'BASH'
i=0
while command -v fuser >/dev/null 2>&1 && (fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 || fuser /var/lib/dpkg/lock >/dev/null 2>&1); do
  i=$((i+1))
  if [ "$i" -gt 60 ]; then
    echo "[dply] dpkg lock held by another process for >5 minutes; aborting." >&2
    exit 100
  fi
  HOLDERS=$(fuser /var/lib/dpkg/lock-frontend 2>/dev/null | tr -d ' ')
  echo "[dply] waiting for dpkg lock to free (held by PID(s): ${HOLDERS:-?}) — attempt $i/60…"
  sleep 5
done
mkdir -p /etc/apt/apt.conf.d
cat > /etc/apt/apt.conf.d/99dply-noninteractive <<'APTCONF'
Dpkg::Options { "--force-confdef"; "--force-confold"; };
APT::Get::Assume-Yes "true";
DPkg::Lock::Timeout "300";
APTCONF
dpkg --force-confdef --force-confold --configure -a 2>&1 || true
BASH;
        $cmd = $this->privilegedCommand($server, 'export DEBIAN_FRONTEND=noninteractive; '.$prelude.'; '.$script.' 2>&1');

        $pending = '';
        [$out, $exit] = $ssh->execWithCallbackAndExit($cmd, function (string $chunk) use (&$pending, $emitter): void {
            $pending .= $chunk;
            while (($pos = strpos($pending, "\n")) !== false) {
                $line = rtrim(substr($pending, 0, $pos), "\r");
                $pending = substr($pending, $pos + 1);
                if ($line !== '') {
                    $emitter($line, 'info', 'install');
                }
            }
        }, 900);
        if (trim($pending) !== '') {
            $emitter(rtrim($pending, "\r\n"), 'info', 'install');
        }

        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(sprintf(
                'Installer for %s failed (exit %d): %s',
                $this->target,
                $exit,
                trim(substr($out, -500)),
            ));
        }
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    protected function executeStageProvision(Server $server, $sites): void
    {
        $ssh = new SshConnection($server);

        $this->writeRemoteFile(
            $server,
            $ssh,
            CaddyEdgeBackendLayout::CADDYFILE_PATH,
            CaddyEdgeBackendLayout::canonicalCaddyfile(),
        );

        $ssh->exec($this->privilegedCommand(
            $server,
            'mkdir -p /etc/caddy/sites-enabled /var/log/caddy /etc/traefik/dynamic /etc/haproxy /etc/envoy && '.CaddyRuntimeOwnership::shell(),
        ), 30);

        foreach ($sites as $site) {
            $basename = $this->basenameFor($site);
            $port = $this->backendPort($site);

            // Caddy backend on per-site high port. Same builder caddy-edge uses;
            // the only difference is the listen port.
            $backend = app(CaddySiteConfigBuilder::class)->build($site, $port);
            $this->writeRemoteFile($server, $ssh, '/etc/caddy/sites-enabled/'.$basename.'-backend.caddy', $backend);

            $tlsFront = app(CaddySiteConfigBuilder::class)->buildEdgeTlsFront($site, $port);
            $tlsPath = '/etc/caddy/sites-enabled/'.$basename.'-tls.caddy';
            if ($tlsFront === '') {
                $ssh->exec($this->privilegedCommand($server, 'rm -f '.escapeshellarg($tlsPath)), 15);
            } else {
                $this->writeRemoteFile($server, $ssh, $tlsPath, $tlsFront);
            }

            $legacyPath = '/etc/caddy/sites-enabled/'.$basename.'.caddy';
            $ssh->exec($this->privilegedCommand($server, 'rm -f '.escapeshellarg($legacyPath)), 15);

            if ($this->target === 'traefik') {
                // Traefik dynamic config: routers + services. The Host header
                // is matched against the site's hostnames; matched traffic
                // proxies to the Caddy upstream on its high port.
                $dyn = app(TraefikSiteConfigBuilder::class)->build($site, $port);
                $this->writeRemoteFile($server, $ssh, '/etc/traefik/dynamic/'.$basename.'.yml', $dyn);
            }
        }

        if ($this->target === 'traefik') {
            $this->writeTraefikStaticConfig($server, $ssh, listenPort: 8080);
        }
        if ($this->target === 'haproxy') {
            $this->writeHAProxyEdgeConfig($server, $ssh, $sites, listenPort: 8080);
        }
        if ($this->target === 'envoy') {
            $this->writeEnvoyEdgeConfig($server, $ssh, $sites, listenPort: 8080);
        }
    }

    protected function executeStageValidate(Server $server): void
    {
        $ssh = new SshConnection($server);
        $ssh->exec($this->privilegedCommand(
            $server,
            CaddyEdgeBackendLayout::stripLegacySiteFragmentsShell().'; dply_strip_legacy_caddy_site_fragments',
        ), 30);

        $cmd = match ($this->target) {
            'traefik' => 'caddy validate --config /etc/caddy/Caddyfile',
            'haproxy' => 'haproxy -c -f /etc/haproxy/haproxy.cfg && caddy validate --config /etc/caddy/Caddyfile',
            'envoy' => 'envoy --mode validate -c /etc/envoy/envoy.yaml && caddy validate --config /etc/caddy/Caddyfile',
        };

        $out = $ssh->exec($this->privilegedCommand($server, $cmd.' 2>&1'), 60);
        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(sprintf(
                '%s validate failed (exit %d): %s',
                $this->target,
                $exit,
                trim(substr($out, -500)),
            ));
        }
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    protected function executeStageCutover(Server $server, $sites, string $previousWebserver): void
    {
        $ssh = new SshConnection($server);

        // If the previous webserver was Caddy, its per-site :80 configs
        // collide with the new -backend.caddy files (both would bind on
        // caddy reload). Remove them so Caddy reloads cleanly to backend
        // ports only. Also strip legacy per-site :80 files on every edge
        // cutover — they prevent Envoy/HAProxy/Traefik from binding :80.
        foreach ($sites as $site) {
            $basename = $this->basenameFor($site);
            $oldPath = '/etc/caddy/sites-enabled/'.$basename.'.caddy';
            $ssh->exec($this->privilegedCommand($server, 'rm -f '.escapeshellarg($oldPath)), 15);
        }

        // Land the edge proxy config bound to :80.
        match ($this->target) {
            'traefik' => $this->writeTraefikStaticConfig($server, $ssh, listenPort: 80),
            'haproxy' => $this->writeHAProxyEdgeConfig($server, $ssh, $sites, listenPort: 80),
            'envoy' => $this->writeEnvoyEdgeConfig($server, $ssh, $sites, listenPort: 80),
        };

        // Stop the previous webserver (free :80) — except when it's Caddy,
        // since Caddy is the backend now and we just want it to keep running
        // on high ports.
        if ($previousWebserver !== 'caddy') {
            $prevUnit = $this->systemdUnitFor($previousWebserver);
            if ($prevUnit !== null) {
                $ssh->exec($this->privilegedCommand($server, sprintf('systemctl stop %s', escapeshellarg($prevUnit))), 30);
            }
        }

        $currentEdge = $server->edgeProxy();
        if ($currentEdge !== null && $currentEdge !== $this->target) {
            $this->stopEdgeProxyUnit($ssh, $server, $currentEdge);
        }

        // Ensure Caddy loads backend configs without binding :80 (default apt
        // Caddyfile serves :80 and blocks Envoy).
        $releaseOut = $ssh->exec($this->privilegedCommand(
            $server,
            CaddyEdgeBackendLayout::releasePort80Shell().' 2>&1',
        ), 45);
        $releaseExit = $ssh->lastExecExitCode();
        if ($releaseExit !== null && $releaseExit !== 0) {
            throw new \RuntimeException(sprintf(
                'Failed to release Caddy from port :80 (exit %d): %s',
                $releaseExit,
                trim(substr($releaseOut, -800)),
            ));
        }

        $ssh->exec($this->privilegedCommand(
            $server,
            '(systemctl is-active --quiet caddy && systemctl reload caddy) || systemctl enable --now caddy',
        ), 30);

        // Start the edge proxy on :80.
        $edgeUnit = match ($this->target) {
            'traefik' => 'traefik',
            'haproxy' => 'haproxy',
            'envoy' => 'envoy',
        };

        if ($this->target === 'envoy') {
            $prepOut = $ssh->exec($this->privilegedCommand($server, EnvoyAdminScript::prepareForCutoverScript().' 2>&1'), 30);
            $prepExit = $ssh->lastExecExitCode();
            if ($prepExit !== null && $prepExit !== 0) {
                throw new \RuntimeException(sprintf(
                    'Cannot start Envoy — port :80 is not free (exit %d): %s',
                    $prepExit,
                    trim(substr($prepOut, -800)),
                ));
            }
            $startOut = $ssh->exec($this->privilegedCommand($server, 'systemctl enable --now envoy 2>&1'), 60);
            $startExit = $ssh->lastExecExitCode();
            if ($startExit !== null && $startExit !== 0) {
                throw new \RuntimeException(sprintf(
                    'Failed to start envoy during cutover (exit %d): %s',
                    $startExit,
                    trim(substr($startOut, -500)),
                ));
            }
            $this->assertEnvoyAdminReady($server, $ssh);

            return;
        }

        // `enable --now` brings the unit up fresh with the new on-disk
        // config; a subsequent reload would be redundant. We attempt it
        // anyway so an existing-and-already-running daemon (rare retry
        // case) re-reads its config — but a reload-not-applicable error
        // (traefik's older units, daemons without ExecReload) must NOT
        // fail the cutover. `|| true` swallows that case; the start above
        // is the load-bearing check.
        $cmd = sprintf('systemctl enable --now %1$s && (systemctl reload %1$s 2>/dev/null || true)', escapeshellarg($edgeUnit));
        $out = $ssh->exec($this->privilegedCommand($server, $cmd.' 2>&1'), 60);
        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(sprintf(
                'Failed to start %s during cutover (exit %d): %s',
                $edgeUnit,
                $exit,
                trim(substr($out, -500)),
            ));
        }
    }

    private function assertEnvoyAdminReady(Server $server, SshConnection $ssh): void
    {
        $out = $ssh->exec($this->privilegedCommand($server, EnvoyAdminScript::waitUntilReady().' 2>&1'), 60);
        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(sprintf(
                'Envoy started but admin API at 127.0.0.1:9901 did not become ready (exit %d): %s',
                $exit,
                trim(substr($out, -800)),
            ));
        }
    }

    /**
     * Apt + repo install script for the chosen edge proxy. Chains the
     * Caddy installer first since Caddy is the per-site backend in every
     * dply edge-proxy setup. Mirrors the helpers in SwitchServerWebserverJob.
     */
    private function installerScriptFor(string $target): string
    {
        $caddy = $this->caddyInstallScript();

        return match ($target) {
            'traefik' => $caddy."\n".$this->traefikInstallScript(),
            'haproxy' => $caddy."\n".$this->aptInstallIdempotent('haproxy')."\n".$this->aptInstallIdempotent('socat'),
            'envoy' => $caddy."\n".$this->envoyInstallScript(),
        };
    }

    private function caddyInstallScript(): string
    {
        // Verbatim copy of the script in SwitchServerWebserverJob. They
        // could share a trait but the duplication is small and keeps the
        // job files self-contained for now.
        return <<<'BASH'
set -euo pipefail
if command -v caddy >/dev/null 2>&1; then
  echo "[dply] caddy already installed; skipping."
else
  rm -f /etc/apt/sources.list.d/caddy-stable.list /etc/apt/sources.list.d/caddy.list
  apt-get install -y --no-install-recommends debian-keyring debian-archive-keyring apt-transport-https curl gnupg
  curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable.gpg
  cat > /etc/apt/sources.list.d/caddy-stable.list <<'SOURCES'
deb [signed-by=/usr/share/keyrings/caddy-stable.gpg] https://dl.cloudsmith.io/public/caddy/stable/deb/debian any-version main
deb-src [signed-by=/usr/share/keyrings/caddy-stable.gpg] https://dl.cloudsmith.io/public/caddy/stable/deb/debian any-version main
SOURCES
  apt-get update -y
  apt-get install -y --no-install-recommends caddy
  systemctl stop caddy 2>/dev/null || true
fi
command -v caddy >/dev/null 2>&1 || { echo "[dply] caddy binary not on PATH after install" >&2; exit 127; }
getent group caddy >/dev/null 2>&1 || groupadd --system caddy
id -u caddy >/dev/null 2>&1 || useradd --system --gid caddy --no-create-home \
  --home-dir /var/lib/caddy --shell /usr/sbin/nologin caddy
mkdir -p /var/lib/caddy /var/log/caddy
chown -R caddy:caddy /var/lib/caddy /var/log/caddy
chmod 0755 /var/log/caddy
chmod 0750 /var/lib/caddy
BASH;
    }

    private function traefikInstallScript(): string
    {
        return TraefikStaticConfigOptions::installScript();
    }

    private function envoyInstallScript(): string
    {
        return EnvoyStaticConfigOptions::installScript();
    }

    private function aptInstallIdempotent(string $package): string
    {
        return sprintf(
            'set -euo pipefail; '
            .'if command -v %1$s >/dev/null 2>&1; then '
            .'  echo "[dply] %1$s already installed; skipping."; '
            .'else '
            .'  apt-get update -y && apt-get install -y --no-install-recommends %1$s; '
            .'  systemctl stop %1$s 2>/dev/null || true; '
            .'fi; '
            .'command -v %1$s >/dev/null 2>&1 || { echo "[dply] %1$s binary not on PATH after install" >&2; exit 127; }',
            $package,
        );
    }

    private function writeTraefikStaticConfig(Server $server, SshConnection $ssh, int $listenPort): void
    {
        $path = '/etc/traefik/traefik.yml';
        $ssh->exec($this->privilegedCommand($server, sprintf(
            '[ -f %1$s ] && [ ! -f %1$s.dply-bak ] && cp %1$s %1$s.dply-bak || true',
            escapeshellarg($path),
        )), 15);

        $contents = app(TraefikStaticConfigOptions::class)->renderCanonicalStaticYaml($listenPort);
        $this->writeRemoteFile($server, $ssh, $path, $contents);
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function writeHAProxyEdgeConfig(Server $server, SshConnection $ssh, $sites, int $listenPort): void
    {
        $path = '/etc/haproxy/haproxy.cfg';
        $ssh->exec($this->privilegedCommand($server, sprintf(
            '[ -f %1$s ] && [ ! -f %1$s.dply-bak ] && cp %1$s %1$s.dply-bak || true',
            escapeshellarg($path),
        )), 15);

        $contents = app(HAProxyEdgeConfigBuilder::class)->build(
            $sites,
            $listenPort,
            fn (Site $s): int => $this->backendPort($s),
        );
        $this->writeRemoteFile($server, $ssh, $path, $contents);
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function writeEnvoyEdgeConfig(Server $server, SshConnection $ssh, $sites, int $listenPort): void
    {
        $path = '/etc/envoy/envoy.yaml';
        $ssh->exec($this->privilegedCommand($server, sprintf(
            '[ -f %1$s ] && [ ! -f %1$s.dply-bak ] && cp %1$s %1$s.dply-bak || true',
            escapeshellarg($path),
        )), 15);

        $contents = app(EnvoyEdgeConfigBuilder::class)->build(
            $sites,
            $listenPort,
            fn (Site $s): int => $this->backendPort($s),
        );
        $this->writeRemoteFile($server, $ssh, $path, $contents);
    }

    /**
     * Stable per-site Caddy backend port. Reuses any value already pinned
     * in site meta by SiteTraefikProvisioner; falls back to a deterministic
     * crc32-derived port in the 20000-40000 range.
     */
    private function backendPort(Site $site): int
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $existing = $meta['traefik_backend_port'] ?? null;
        if (is_numeric($existing) && (int) $existing >= 20000) {
            return (int) $existing;
        }

        return 20000 + (abs(crc32((string) $site->getKey())) % 20000);
    }

    private function basenameFor(Site $site): string
    {
        return method_exists($site, 'webserverConfigBasename')
            ? (string) $site->webserverConfigBasename()
            : (string) $site->slug;
    }

    private function systemdUnitFor(string $webserver): ?string
    {
        return match ($webserver) {
            'nginx' => 'nginx',
            'apache' => 'apache2',
            'caddy' => 'caddy',
            'openlitespeed' => 'lshttpd',
            default => null,
        };
    }

    private function stopEdgeProxyUnit(SshConnection $ssh, Server $server, string $edge): void
    {
        $unit = match ($edge) {
            'traefik' => 'traefik',
            'haproxy' => 'haproxy',
            'envoy' => 'envoy',
            default => null,
        };

        if ($unit === null) {
            return;
        }

        $cmd = sprintf(
            'systemctl disable --now %1$s 2>/dev/null || systemctl stop %1$s 2>/dev/null || true',
            escapeshellarg($unit),
        );
        $ssh->exec($this->privilegedCommand($server, $cmd), 30);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordAudit(Server $server, string $action, array $payload, float $startedAt, ?string $resultStatus = null): void
    {
        ServerWebserverAuditEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $this->userId,
            'action' => $action,
            'risk' => RiskLevel::MutatingRecoverable->value,
            'transport' => ServerWebserverAuditEvent::TRANSPORT_WEB,
            'summary' => __('Edge proxy :action: :target', [
                'action' => str_contains($action, 'failed') ? 'add failed' : 'added',
                'target' => $this->target,
            ]),
            'payload' => $payload,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'result_status' => $resultStatus ?? ServerWebserverAuditEvent::RESULT_FAILURE,
        ]);
    }
}
