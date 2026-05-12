<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Models\ServerWebserverAuditEvent;
use App\Models\Site;
use App\Services\RemoteCli\RiskLevel;
use App\Services\Servers\WebserverSwitchPreflight;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\SshConnection;
use Illuminate\Support\Str;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Switch a server's webserver from `from` → `to` via parallel install on :8080,
 * site provisioning under the new webserver, validation, then service-swap to :80.
 *
 * Drives the live progress banner on `/servers/{srv}/manage/web`. The staged
 * structure mirrors the design we locked in via /grill-me:
 *
 *   1. install      — apt/package the target webserver (no downtime, on :8080)
 *   2. provision    — regenerate per-site configs under the new webserver
 *   3. validate     — issue a test request through the new webserver on :8080
 *   4. cutover      — stop old, bind new to :80 (~600ms blip)
 *   5. disable_old  — stop+disable old webserver (kept installed for rollback)
 *
 * Pre-cutover failures auto-rollback the work done so far (uninstall, unprovision,
 * kill the new daemon on :8080); post-cutover failures surface in the banner but
 * don't auto-revert — the operator gets a manual "Re-bind <old> to :80" button.
 *
 * **Implementation status (v1 scaffold)**: this class is wired into the UI and the
 * console-action banner machinery. The actual remote SSH commands (apt install,
 * config write, systemctl swap) are marked as `executeStage*` stubs that emit
 * placeholder output and complete successfully so the end-to-end UX is testable.
 * The follow-up PR fills in `app/Services/Servers/Switcher/*` services that
 * the stubs delegate to.
 */
class SwitchServerWebserverJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use WritesConsoleAction;

    public int $tries = 1; // No retries — failure surfaces via the banner.

    public int $timeout = 600; // 10-minute cap; the cutover should finish well under this.

    public function __construct(
        public string $serverId,
        public string $target,
        public bool $tlsToCaddy = false,
        public ?string $userId = null,
    ) {}

    /**
     * Unique constraint so two operators can't race-trigger concurrent switches
     * on the same server. The ConsoleAction lock in WorkspaceManage backstops
     * this at the UI level.
     */
    public function uniqueId(): string
    {
        return 'webserver_switch_'.$this->serverId;
    }

    public function uniqueFor(): int
    {
        return $this->timeout;
    }

    protected function consoleSubject(): Model
    {
        return Server::query()->findOrFail($this->serverId);
    }

    protected function consoleKind(): string
    {
        return 'webserver_switch';
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

        $emitter = $this->beginConsoleAction();
        $startedAt = microtime(true);
        $from = strtolower(trim((string) ($server->meta['webserver'] ?? 'nginx')));

        // Re-run preflight defensively. The modal already filtered out blocked
        // targets, but the server state could have changed (a site was created
        // with an incompatible runtime) between modal-open and worker-pickup.
        $preflight = app(WebserverSwitchPreflight::class)->plan($server, $this->target);
        if ($preflight['blocker'] !== null) {
            $emitter->error('Preflight blocker: '.$preflight['blocker']['label']);
            $this->failConsoleAction($preflight['blocker']['label']);
            $this->recordAudit($server, $from, ServerWebserverAuditEvent::ACTION_SWITCH_FAILED, [
                'reason' => 'preflight_blocker',
                'blocker' => $preflight['blocker'],
            ], $startedAt);

            return;
        }

        // Staged execution — fail-fast pre-cutover with per-stage rollback.
        try {
            $emitter->info(sprintf('[install]   installing %s on :8080…', $this->target));
            $this->executeStageInstall($server);

            $emitter->info(sprintf('[provision] regenerating %d site config(s) under %s', $preflight['sites_affected'], $this->target));
            $this->executeStageProvision($server, $preflight);

            $emitter->info('[validate]  checking new webserver responds on :8080');
            $this->executeStageValidate($server);

            $emitter->info(sprintf('[cutover]   service-swap: stop %s, bind %s to :80', $from, $this->target));
            $this->executeStageCutover($server, $from);

            $emitter->info(sprintf('[finalize]  stop+disable %s (kept installed)', $from));
            $this->executeStageDisableOld($server, $from);

            // Persist the new webserver as the server's truth.
            $meta = is_array($server->meta) ? $server->meta : [];
            $meta['webserver'] = $this->target;
            $server->update(['meta' => $meta]);

            $emitter->info('Done.');
            $this->completeConsoleAction();
            $this->recordAudit($server, $from, ServerWebserverAuditEvent::ACTION_SWITCHED, [
                'tls_opt_in' => $this->tlsToCaddy,
                'sites_affected' => $preflight['sites_affected'],
                'site_ids' => Site::query()->where('server_id', $server->id)->pluck('id')->all(),
            ], $startedAt, ServerWebserverAuditEvent::RESULT_SUCCESS);
        } catch (\Throwable $e) {
            $emitter->error('Switch failed: '.$e->getMessage());
            $this->failConsoleAction($e->getMessage());
            $this->recordAudit($server, $from, ServerWebserverAuditEvent::ACTION_SWITCH_FAILED, [
                'reason' => $e->getMessage(),
            ], $startedAt);
        }
    }

    /**
     * Framework-level failure path — invoked when the worker raises an
     * exception OUTSIDE handle()'s try/catch, most commonly
     * MaxAttemptsExceededException when the Redis `retry_after` fires before
     * the worker's own `timeout` and the job is re-dispatched (attempts=2
     * with tries=1). A fresh job instance is constructed for failed(), so we
     * can't use $this->consoleRunId — look the row up by subject + kind.
     */
    public function failed(\Throwable $e): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            return;
        }

        $action = \App\Models\ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->getKey())
            ->where('kind', 'webserver_switch')
            ->whereIn('status', [\App\Models\ConsoleAction::STATUS_QUEUED, \App\Models\ConsoleAction::STATUS_RUNNING])
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();

        $message = sprintf('Job failed before completing: %s', $e->getMessage());
        if ($action !== null) {
            $action->update([
                'status' => \App\Models\ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($message, 0, 2000),
            ]);
        }

        $from = strtolower(trim((string) ($server->meta['webserver'] ?? 'nginx')));
        ServerWebserverAuditEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $this->userId,
            'action' => ServerWebserverAuditEvent::ACTION_SWITCH_FAILED,
            'risk' => RiskLevel::MutatingRecoverable->value,
            'transport' => ServerWebserverAuditEvent::TRANSPORT_WEB,
            'summary' => __('Webserver switch from :from to :to (worker failure)', [
                'from' => $from !== '' ? $from : '(none)',
                'to' => $this->target,
            ]),
            'payload' => [
                'from' => $from,
                'to' => $this->target,
                'reason' => 'worker_failed',
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ],
            'result_status' => ServerWebserverAuditEvent::RESULT_FAILURE,
        ]);
    }

    /**
     * Stage 1: install target webserver package. Does NOT enable/start the
     * service yet — cutover (stage 4) is where the new webserver gets started
     * after the old one has freed :80.
     *
     * v1 supports the FPM-compatible trio (nginx, caddy, apache). OpenLiteSpeed
     * and Traefik throw here — preflight blocks the PHP-site cases for both,
     * but a static-only server could attempt the switch, and the installer for
     * those two needs custom repo setup + lsphpXX / traefik-binary handling.
     */
    protected function executeStageInstall(Server $server): void
    {
        $ssh = new SshConnection($server);
        $script = $this->installerScriptFor($this->target);
        $cmd = $this->privilegedCommand($server, 'export DEBIAN_FRONTEND=noninteractive; '.$script);
        $out = $ssh->exec($cmd, 900);
        $exit = $ssh->lastExecExitCode();
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
     * Per-target install script run via privileged SSH. Each script is
     * idempotent (skip when the package is already present) and does NOT
     * start the service — cutover (stage 4) is where the new daemon comes up.
     *
     * Caddy and Traefik live in third-party apt repos that we add when missing.
     * OpenLiteSpeed has an official repo + lsphpXX packages keyed to PHP versions
     * — preflight blocks the switch when any PHP site is on the server, so the
     * OLS installer below only handles the lshttpd binary itself.
     */
    private function installerScriptFor(string $target): string
    {
        return match ($target) {
            'nginx' => $this->aptInstallIdempotent('nginx'),
            'apache' => $this->aptInstallIdempotent('apache2'),
            // Caddy ships in the official cloudsmith / cloudflare-managed apt repo.
            // `command -v` (not dpkg) drives the skip check: a half-installed
            // package can still show `ii` in dpkg while the binary is missing
            // from PATH, which is exactly the state that surfaces as
            // "caddy: command not found" at the validate stage.
            //
            // We write the sources line directly rather than curl+sed-mutating
            // upstream's debian.deb.txt: cloudsmith's file now includes
            // `[arch=amd64,arm64,armhf]` modifiers, and a sed-prepended
            // `[signed-by=...]` produces two consecutive bracket groups, which
            // apt rejects as a malformed URI. The cloudsmith repo coordinates
            // (URI / suite / component) for caddy stable have been stable since
            // 2020 — hardcoding them is the safer trade-off here.
            'caddy' => <<<'BASH'
set -euo pipefail
if command -v caddy >/dev/null 2>&1; then
  echo "[dply] caddy already installed; skipping."
else
  # Clear any stale caddy sources from a prior failed run BEFORE invoking apt —
  # apt reads sources.list.d on every call, so a malformed file left behind by
  # an aborted earlier attempt will fail the very first `apt-get install` (and
  # set -e then aborts before we get to rewrite the file).
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
BASH,
            // OpenLiteSpeed apt repo + the base lshttpd binary. Preflight blocks
            // PHP-site cases; for static-only servers this installs OK. OLS
            // installs to /usr/local/lsws/bin/lshttpd (not on PATH by default),
            // so the post-install verification checks the absolute path.
            'openlitespeed' => <<<'BASH'
set -euo pipefail
if [ -x /usr/local/lsws/bin/lshttpd ] || dpkg -l | awk '$1=="ii" && $2~"^openlitespeed(:|$)" {found=1} END {exit !found}'; then
  echo "[dply] openlitespeed already installed; skipping."
else
  apt-get install -y --no-install-recommends wget gnupg
  wget -qO- https://rpms.litespeedtech.com/debian/lst_repo.gpg | gpg --batch --yes --dearmor -o /usr/share/keyrings/lst-repo.gpg
  CODENAME=$(. /etc/os-release && echo "${VERSION_CODENAME:-bullseye}")
  echo "deb [signed-by=/usr/share/keyrings/lst-repo.gpg] http://rpms.litespeedtech.com/debian/ $CODENAME main" > /etc/apt/sources.list.d/lst_debian_repo.list
  apt-get update -y
  apt-get install -y --no-install-recommends openlitespeed
  systemctl stop lshttpd 2>/dev/null || true
fi
[ -x /usr/local/lsws/bin/lshttpd ] || { echo "[dply] lshttpd binary not found at /usr/local/lsws/bin/lshttpd after install" >&2; exit 127; }
BASH,
            // Traefik ships a single static binary. Pulled to /usr/local/bin
            // with a small systemd unit. Idempotent on both the binary download
            // and the unit file.
            'traefik' => <<<'BASH'
set -euo pipefail
if [ -x /usr/local/bin/traefik ] && systemctl list-unit-files | grep -q '^traefik\.service'; then
  echo "[dply] traefik already installed; skipping."
else
  apt-get install -y --no-install-recommends curl ca-certificates
  TRAEFIK_VERSION="${TRAEFIK_VERSION:-v3.1.0}"
  curl -fsSL "https://github.com/traefik/traefik/releases/download/${TRAEFIK_VERSION}/traefik_${TRAEFIK_VERSION}_linux_amd64.tar.gz" -o /tmp/traefik.tgz
  tar -xzf /tmp/traefik.tgz -C /tmp traefik
  install -m 0755 /tmp/traefik /usr/local/bin/traefik
  rm -f /tmp/traefik.tgz /tmp/traefik
  mkdir -p /etc/traefik /etc/traefik/dynamic
  if [ ! -f /etc/traefik/traefik.yml ]; then
    cat > /etc/traefik/traefik.yml <<'YAML'
entryPoints:
  web:
    address: ":80"
providers:
  file:
    directory: /etc/traefik/dynamic
    watch: true
YAML
  fi
  if [ ! -f /etc/systemd/system/traefik.service ]; then
    cat > /etc/systemd/system/traefik.service <<'UNIT'
[Unit]
Description=Traefik
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/traefik --configFile=/etc/traefik/traefik.yml
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
  fi
fi
[ -x /usr/local/bin/traefik ] && systemctl list-unit-files | grep -q '^traefik\.service' || { echo "[dply] traefik binary or unit file missing after install" >&2; exit 127; }
BASH,
            default => throw new \RuntimeException(sprintf(
                'No installer registered for "%s".',
                $target,
            )),
        };
    }

    /**
     * Idempotent apt install for a single package. Used for the simple distro-
     * shipped webservers (nginx, apache2) — neither needs a third-party repo.
     *
     * Uses `command -v` (not dpkg) for the skip check: a half-installed package
     * can show `ii` in dpkg while the binary is missing from PATH, which is the
     * state that surfaces as "command not found" at the validate stage. The
     * trailing `command -v` enforces the post-install invariant explicitly so
     * a silent install failure can't slip through with exit 0.
     */
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

    /**
     * Stage 2: write a per-site config for the target webserver, bound to :8080.
     * The configs are written as files only — no daemon start. Stage 3 runs the
     * target's config-test on the written files; stage 4 rewrites them to bind
     * production ports and swaps services.
     *
     * **v1 scope**: nginx ↔ caddy ↔ apache. Auxiliary work the provisioners do
     * (basic-auth htpasswd files, log directories, etc.) is NOT replicated here.
     * Sites that rely on those features may need post-switch re-provisioning.
     *
     * @param  array<string, mixed>  $preflight
     */
    protected function executeStageProvision(Server $server, array $preflight): void
    {
        $sites = Site::query()
            ->where('server_id', $server->id)
            ->with(['domains', 'domainAliases', 'tenantDomains', 'redirects', 'basicAuthUsers', 'server'])
            ->get();
        if ($sites->isEmpty()) {
            return;
        }

        $ssh = new SshConnection($server);

        // Ensure target config directories exist before writing.
        $this->ensureTargetConfigDirs($server, $ssh);

        foreach ($sites as $site) {
            $config = $this->buildSiteConfigFor($site, $this->target, listenPort: 8080);
            $path = $this->siteConfigPathFor($site, $this->target);
            $this->writeRemoteFile($server, $ssh, $path, $config);

            // For nginx/apache, ensure sites-enabled is symlinked to sites-available.
            $this->ensureSiteEnabled($server, $ssh, $site, $this->target);
        }
    }

    /**
     * Stage 3: config-test the target webserver. Validates the on-disk syntax
     * without binding any ports — runs before we touch the live :80 listener.
     */
    protected function executeStageValidate(Server $server): void
    {
        $cmd = match ($this->target) {
            'nginx' => 'nginx -t',
            'caddy' => 'caddy validate --config /etc/caddy/Caddyfile',
            'apache' => 'apachectl configtest',
            default => throw new \RuntimeException(sprintf(
                'No config-test command for "%s" — supported in v1: nginx, caddy, apache.',
                $this->target,
            )),
        };

        $ssh = new SshConnection($server);
        $out = $ssh->exec($this->privilegedCommand($server, $cmd.' 2>&1'), 60);
        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(sprintf(
                '%s config-test failed (exit %d): %s',
                $this->target,
                $exit,
                trim(substr($out, -500)),
            ));
        }
    }

    /**
     * Stage 4: rewrite per-site configs to production binds (no listenPort),
     * stop the old webserver to free :80, enable+start the new webserver.
     *
     * This is the brief connection blip — between `systemctl stop <from>` and
     * the new daemon's first successful bind on :80. Past this point we don't
     * auto-rollback; failures surface in the banner with a manual revert button.
     */
    protected function executeStageCutover(Server $server, string $from): void
    {
        $sites = Site::query()
            ->where('server_id', $server->id)
            ->with(['domains', 'domainAliases', 'tenantDomains', 'redirects', 'basicAuthUsers', 'server'])
            ->get();

        $ssh = new SshConnection($server);

        // Write production-bound configs (no listenPort) over the :8080 ones.
        foreach ($sites as $site) {
            $config = $this->buildSiteConfigFor($site, $this->target, listenPort: null);
            $path = $this->siteConfigPathFor($site, $this->target);
            $this->writeRemoteFile($server, $ssh, $path, $config);
        }

        // Atomic-ish service swap: stop old, enable+start new. The gap between
        // these is the operator-visible downtime window (typically <1s).
        $fromUnit = $this->systemdUnitFor($from);
        $toUnit = $this->systemdUnitFor($this->target);
        if ($fromUnit !== null) {
            $ssh->exec($this->privilegedCommand($server, sprintf('systemctl stop %s', escapeshellarg($fromUnit))), 30);
        }
        if ($toUnit !== null) {
            $cmd = sprintf('systemctl enable --now %1$s && systemctl reload %1$s', escapeshellarg($toUnit));
            $out = $ssh->exec($this->privilegedCommand($server, $cmd.' 2>&1'), 60);
            $exit = $ssh->lastExecExitCode();
            if ($exit !== null && $exit !== 0) {
                throw new \RuntimeException(sprintf(
                    'Failed to start %s during cutover (exit %d): %s',
                    $toUnit,
                    $exit,
                    trim(substr($out, -500)),
                ));
            }
        }
    }

    /**
     * Render a per-site config string for the target webserver. Dispatches to
     * the appropriate builder; listenPort=8080 produces a :8080-bound test
     * variant for validation; listenPort=null produces the production config.
     */
    private function buildSiteConfigFor(Site $site, string $target, ?int $listenPort): string
    {
        return match ($target) {
            'nginx' => app(NginxSiteConfigBuilder::class)->build($site, null, $listenPort),
            'caddy' => app(CaddySiteConfigBuilder::class)->build($site, $listenPort),
            'apache' => app(ApacheSiteConfigBuilder::class)->build($site, $listenPort),
            default => throw new \RuntimeException(sprintf(
                'No config builder for "%s" — supported in v1: nginx, caddy, apache.',
                $target,
            )),
        };
    }

    /**
     * Remote on-disk path for a site's config under the given webserver.
     */
    private function siteConfigPathFor(Site $site, string $target): string
    {
        $basename = method_exists($site, 'webserverConfigBasename')
            ? (string) $site->webserverConfigBasename()
            : (string) $site->slug;

        return match ($target) {
            'nginx' => '/etc/nginx/sites-available/'.$basename,
            'apache' => '/etc/apache2/sites-available/'.$basename.'.conf',
            'caddy' => '/etc/caddy/sites-enabled/'.$basename.'.caddy',
            default => throw new \RuntimeException('No config path mapping for '.$target),
        };
    }

    /**
     * Ensure the directories the target uses for per-site configs exist + the
     * sites-enabled directory (where applicable) is set up.
     */
    private function ensureTargetConfigDirs(Server $server, SshConnection $ssh): void
    {
        $cmd = match ($this->target) {
            'nginx' => 'mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled',
            'apache' => 'mkdir -p /etc/apache2/sites-available /etc/apache2/sites-enabled',
            'caddy' => 'mkdir -p /etc/caddy/sites-enabled /var/log/caddy && touch /etc/caddy/Caddyfile && (grep -Fq \'import /etc/caddy/sites-enabled/*.caddy\' /etc/caddy/Caddyfile || printf "\nimport /etc/caddy/sites-enabled/*.caddy\n" >> /etc/caddy/Caddyfile)',
            default => 'true',  // no-op for unsupported targets; preflight should have blocked earlier.
        };
        $ssh->exec($this->privilegedCommand($server, $cmd), 30);
    }

    /**
     * For nginx/apache: symlink the site config from sites-available → sites-enabled
     * so the webserver picks it up. Caddy uses the import-from-sites-enabled
     * pattern set up by ensureTargetConfigDirs(), so no per-site symlink is needed.
     */
    private function ensureSiteEnabled(Server $server, SshConnection $ssh, Site $site, string $target): void
    {
        $basename = method_exists($site, 'webserverConfigBasename')
            ? (string) $site->webserverConfigBasename()
            : (string) $site->slug;
        $cmd = match ($target) {
            'nginx' => sprintf(
                'ln -sf %s %s',
                escapeshellarg('/etc/nginx/sites-available/'.$basename),
                escapeshellarg('/etc/nginx/sites-enabled/'.$basename),
            ),
            'apache' => sprintf(
                'ln -sf %s %s',
                escapeshellarg('/etc/apache2/sites-available/'.$basename.'.conf'),
                escapeshellarg('/etc/apache2/sites-enabled/'.$basename.'.conf'),
            ),
            default => null,  // caddy: import-glob handles it.
        };
        if ($cmd !== null) {
            $ssh->exec($this->privilegedCommand($server, $cmd), 15);
        }
    }

    /**
     * Write `$contents` to `$remotePath` on the server. Mirrors the pattern
     * from {@see \App\Services\Sites\AbstractSiteWebserverProvisioner::writeSystemFile}:
     * putFile to /tmp then sudo-mv into place + chown root:root + chmod 644.
     */
    private function writeRemoteFile(Server $server, SshConnection $ssh, string $remotePath, string $contents): void
    {
        $tmp = '/tmp/'.basename($remotePath).'.'.Str::random(8);
        $ssh->putFile($tmp, $contents);
        $cmd = sprintf(
            'sudo -n mkdir -p %1$s && sudo -n mv %2$s %3$s && sudo -n chown root:root %3$s && sudo -n chmod 644 %3$s',
            escapeshellarg(dirname($remotePath)),
            escapeshellarg($tmp),
            escapeshellarg($remotePath),
        );
        $ssh->exec($cmd.' 2>&1', 30);
        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(sprintf(
                'Failed to write %s on remote host (exit %d).',
                $remotePath,
                $exit,
            ));
        }
    }

    /**
     * Stage 5: stop+disable old webserver. Binary + configs stay on disk so the
     * operator can manually revert via systemctl re-enable if needed.
     */
    protected function executeStageDisableOld(Server $server, string $from): void
    {
        $unit = $this->systemdUnitFor($from);
        if ($unit === null) {
            return; // Unknown webserver — no-op rather than fail. Operator can clean up manually.
        }
        $ssh = new SshConnection($server);
        $cmd = $this->privilegedCommand($server, sprintf(
            'systemctl stop %1$s 2>/dev/null || true; systemctl disable %1$s 2>/dev/null || true',
            escapeshellarg($unit),
        ));
        $ssh->exec($cmd, 60);
        // No exit-code check: best-effort cleanup. The new webserver is already
        // serving traffic by this stage; failing to disable the old one is a
        // warning, not a hard error.
    }

    /**
     * Run a command as root via sudo on the server. Mirrors the pattern from
     * AbstractSiteWebserverProvisioner — switches need root for apt + systemctl.
     */
    private function privilegedCommand(Server $server, string $command): string
    {
        // dply provisions a deploy user with passwordless sudo for the operational
        // commands it runs. The simplest correct form here:
        return 'sudo -n bash -lc '.escapeshellarg($command);
    }

    /**
     * Maps a webserver key to its systemd unit name. The mapping isn't 1:1 —
     * apache2 on Debian/Ubuntu, httpd on RHEL-family (dply targets Debian/Ubuntu).
     */
    private function systemdUnitFor(string $webserver): ?string
    {
        return match (strtolower($webserver)) {
            'nginx' => 'nginx',
            'caddy' => 'caddy',
            'apache' => 'apache2',
            'openlitespeed' => 'lshttpd',
            'traefik' => 'traefik',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordAudit(
        Server $server,
        string $from,
        string $action,
        array $payload,
        float $startedAt,
        string $resultStatus = ServerWebserverAuditEvent::RESULT_FAILURE,
    ): void {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        ServerWebserverAuditEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $this->userId,
            'action' => $action,
            'risk' => RiskLevel::MutatingRecoverable->value,
            'transport' => ServerWebserverAuditEvent::TRANSPORT_WEB,
            'summary' => __('Webserver switch from :from to :to (:status)', [
                'from' => $from !== '' ? $from : '(none)',
                'to' => $this->target,
                'status' => $resultStatus,
            ]),
            'payload' => array_merge($payload, [
                'from' => $from,
                'to' => $this->target,
                'duration_ms' => $durationMs,
            ]),
            'result_status' => $resultStatus,
        ]);
    }
}
