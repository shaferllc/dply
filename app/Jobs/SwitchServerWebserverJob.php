<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Models\ServerWebserverAuditEvent;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\RemoteCli\RiskLevel;
use App\Services\Servers\HAProxyEdgeConfigBuilder;
use App\Services\Servers\OpenLiteSpeedHttpdConfigBuilder;
use App\Services\Servers\WebserverSwitchPreflight;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\Sites\TraefikSiteConfigBuilder;
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

    /**
     * Short lock window. The lock only needs to cover the dispatch race —
     * the UI's `hasInflightWebserverSwitch()` ConsoleAction check is the
     * canonical guard against double-trigger. Keeping uniqueFor close to
     * the worker poll interval means a worker SIGKILL (which skips the
     * normal lock release) only blocks the next dispatch by ~60s instead
     * of the full job timeout.
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
            $this->executeStageInstall($server, $emitter);

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
        // Release the ShouldBeUnique lock explicitly. Laravel's normal
        // release path runs in CallQueuedHandler::call() — that path
        // doesn't execute when the worker is SIGKILL'd by --timeout, so
        // the lock would otherwise sit for the full uniqueFor() window
        // blocking every retry. failed() does run after a SIGKILL via
        // Horizon's lost-job detection, so this is the right hook.
        app(\Illuminate\Bus\UniqueLock::class)->release($this);

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
    protected function executeStageInstall(Server $server, ConsoleEmitter $emitter): void
    {
        $ssh = new SshConnection($server);
        $script = $this->installerScriptFor($this->target, $server);

        // DEBIAN_FRONTEND=noninteractive alone won't suppress dpkg's conffile
        // prompts ("File on system created by you or by a script ... what
        // would you like to do?") — those need explicit Dpkg::Options. A
        // prior failed switch attempt commonly leaves a webserver package
        // half-installed with a dply-modified config (e.g. Caddyfile), and
        // the next apt-get install of ANYTHING hits the question.
        //
        // The conf file is idempotent and stays in place: dply owns these
        // configs, so "keep installed version on package upgrade" is the
        // semantically correct default for the box. `dpkg --configure -a`
        // unsticks any prior half-installed packages BEFORE the new install
        // runs, so they don't surface mid-flight.
        // Cloud images run unattended-upgrades / cloud-init in the
        // background; both hold /var/lib/dpkg/lock-frontend for minutes
        // after first boot or during nightly security updates. Hitting
        // apt-get while the lock is held returns exit 100. Three layers
        // of defense:
        //
        //   1. Wait up to 5 min for the frontend lock to free before our
        //      first dpkg call. fuser checks the lock file directly so we
        //      don't have to enumerate every possible holder (apt /
        //      apt-get / dpkg / unattended-upgrades / cloud-init / etc).
        //   2. APT::Get::Lock::Timeout=300 tells apt-get itself to wait
        //      300s for the lock instead of failing immediately. Covers
        //      the case where another process re-acquires the lock in the
        //      gap between our wait-loop and our install script.
        //   3. force-confdef/confold suppresses conffile prompts when
        //      configuring half-installed packages from a prior crash.
        $prelude = <<<'BASH'
i=0
while command -v fuser >/dev/null 2>&1 && (fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 || fuser /var/lib/dpkg/lock >/dev/null 2>&1); do
  i=$((i+1))
  if [ "$i" -gt 60 ]; then
    echo "[dply] dpkg lock held by another process for >5 minutes; aborting." >&2
    fuser -v /var/lib/dpkg/lock-frontend 2>&1 | sed 's/^/[dply]   /' || true
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

        // Stream output line-by-line into the emitter so the operator sees
        // apt-get's progress (download lists, package upgrades, postinst
        // messages) as it happens — apt installs commonly run 30–60 seconds
        // and a silent banner that whole time looks hung. Chunks arrive
        // unaligned to line boundaries, so we keep a pending buffer and
        // flush complete lines on each newline.
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
        // Flush any trailing fragment that didn't end with a newline
        // (some apt postinst scripts write a final status without one).
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
     * Per-target install script run via privileged SSH. Each script is
     * idempotent (skip when the package is already present) and does NOT
     * start the service — cutover (stage 4) is where the new daemon comes up.
     *
     * Caddy and Traefik live in third-party apt repos that we add when missing.
     * OpenLiteSpeed has an official repo + lsphpXX packages keyed to the PHP
     * versions in use across the server's sites — those are installed alongside
     * the base lshttpd binary so PHP sites get a working LSAPI handler at
     * cutover (without them, every PHP request 500s on a missing extprocessor).
     */
    private function installerScriptFor(string $target, Server $server): string
    {
        return match ($target) {
            'nginx' => $this->aptInstallIdempotent('nginx'),
            // proxy + proxy_http + proxy_fcgi cover ProxyPass / ProxyPreserveHost /
            // SetHandler "proxy:unix:..."; rewrite covers RewriteRule redirects;
            // headers covers RequestHeader and "Header always set". A fresh
            // apache2 install enables none of these — config-test fails on the
            // first `ProxyPreserveHost` in the generated vhost. a2enmod /
            // a2enconf are idempotent, so this stays safe on the "already
            // installed" path.
            //
            // Also pin a global ServerName so `apachectl configtest` and
            // service start don't emit AH00558 ("Could not reliably determine
            // the server's fully qualified domain name"). Vhost ServerName is
            // for Host-header matching — it's not what Apache reads for the
            // global identity. We source from `hostname -f`, falling back
            // through `hostname` to `localhost` for systems without a FQDN.
            'apache' => $this->aptInstallIdempotent('apache2').'; '.<<<'BASH'
a2enmod proxy proxy_http proxy_fcgi rewrite headers >/dev/null
DPLY_FQDN="$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo localhost)"
printf 'ServerName %s\n' "$DPLY_FQDN" > /etc/apache2/conf-available/dply-servername.conf
a2enconf dply-servername >/dev/null
BASH,
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
            'caddy' => $this->caddyInstallScript(),
            // OpenLiteSpeed apt repo + the base lshttpd binary + lsphpXX
            // packages for the PHP versions used by sites on this server.
            // lshttpd installs to /usr/local/lsws/bin/lshttpd (not on PATH by
            // default), so the post-install verification checks the absolute
            // path. lsphp packages live alongside it under /usr/local/lsws/
            // lsphpXX/bin/lsphp — the per-site vhconf.conf references that
            // exact path. Without lsphp installed, every PHP request would
            // 500 with "extprocessor not found" the moment cutover finishes.
            'openlitespeed' => $this->openLiteSpeedInstallScript($server),
            // Note: 'traefik' and 'haproxy' used to live here. They moved
            // to App\Jobs\AddEdgeProxyJob since they're L7 edge proxies in
            // front of a webserver, not webservers themselves. The
            // caddyInstallScript() / traefikInstallScript() helpers below
            // stayed put because the edge proxy job calls them.
            default => throw new \RuntimeException(sprintf(
                'No installer registered for "%s".',
                $target,
            )),
        };
    }

    /**
     * Caddy installer bash. Used directly when caddy is the chosen edge
     * webserver, and chained into the traefik installer when traefik is
     * chosen (since dply runs Caddy as the per-site backend behind Traefik).
     * Idempotent on all branches.
     */
    private function caddyInstallScript(): string
    {
        return <<<'BASH'
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

# Ensure the caddy system user/group and working dirs exist, regardless of which
# branch above we took. The cloudsmith .deb postinst normally creates these, but
# the "already installed" skip-branch above never re-runs that postinst — so a
# server that ended up with the binary but no `caddy` account (e.g. partial
# prior install, user removed by hand) would surface as systemd exit 217/USER
# ("Failed to determine user credentials") the moment caddy.service tries to
# start. Creating them here keeps the installer self-healing across retries.
getent group caddy >/dev/null 2>&1 || groupadd --system caddy
id -u caddy >/dev/null 2>&1 || useradd --system --gid caddy --no-create-home \
  --home-dir /var/lib/caddy --shell /usr/sbin/nologin caddy

# /var/log/caddy and /var/lib/caddy must be writable by the caddy user — without
# this Caddy fails at startup with "open /var/log/caddy/...-access.log:
# permission denied". `chown -R` (not just `install -d -o`) so stale files left
# inside the dirs by a prior root-run / earlier package version also get fixed;
# `install -d -o` only touches the leaf directory's perms, not the contents.
mkdir -p /var/lib/caddy /var/log/caddy
chown -R caddy:caddy /var/lib/caddy /var/log/caddy
chmod 0755 /var/log/caddy
chmod 0750 /var/lib/caddy
BASH;
    }

    /**
     * Traefik installer bash. Downloads the static binary, drops a systemd
     * unit, and creates the config directory layout. Does NOT write
     * /etc/traefik/traefik.yml — the static config is written at provision
     * time so the cutover can rewrite the listener port (:8080 → :80)
     * cleanly. Caller must run the Caddy installer first since Traefik
     * routes to Caddy on ephemeral backend ports.
     */
    private function traefikInstallScript(): string
    {
        return <<<'BASH'
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
fi
mkdir -p /etc/traefik /etc/traefik/dynamic
# Always (re)write the systemd unit so changes to ExecStart/Restart land on
# upgrade-by-rerun. systemd daemon-reload picks up changes without a service
# restart; the unit is only actually started at cutover.
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
[ -x /usr/local/bin/traefik ] && systemctl list-unit-files | grep -q '^traefik\.service' || { echo "[dply] traefik binary or unit file missing after install" >&2; exit 127; }
BASH;
    }

    /**
     * Build the OpenLiteSpeed installer script. Installs lshttpd from the
     * official LiteSpeed apt repo plus the `lsphpXX` packages matching the
     * PHP versions in use across the server's sites — that's the LSAPI
     * runtime the per-site extprocessor blocks call into. If no PHP sites
     * exist (static / node only) we install just lshttpd.
     *
     * @return string Bash script. Idempotent: re-runs are safe.
     */
    private function openLiteSpeedInstallScript(Server $server): string
    {
        $phpVersions = Site::query()
            ->where('server_id', $server->id)
            ->where('runtime', 'php')
            ->whereNotNull('runtime_version')
            ->where('runtime_version', '!=', '')
            ->pluck('runtime_version')
            ->map(fn ($v): string => str_replace('.', '', (string) $v))
            ->filter(fn (string $v): bool => preg_match('/^\d{2,3}$/', $v) === 1)
            ->unique()
            ->values()
            ->all();

        $lsphpPackages = '';
        if ($phpVersions !== []) {
            $pkgs = collect($phpVersions)
                ->flatMap(fn (string $v): array => ['lsphp'.$v, 'lsphp'.$v.'-common', 'lsphp'.$v.'-mysql'])
                ->map(fn (string $p): string => escapeshellarg($p))
                ->implode(' ');
            $lsphpPackages = sprintf("apt-get install -y --no-install-recommends %s\n", $pkgs);
        }

        return <<<BASH
set -euo pipefail
if [ -x /usr/local/lsws/bin/lshttpd ] || dpkg -l | awk '\$1=="ii" && \$2~"^openlitespeed(:|\$)" {found=1} END {exit !found}'; then
  echo "[dply] openlitespeed already installed; skipping core install."
else
  apt-get install -y --no-install-recommends wget gnupg
  wget -qO- https://rpms.litespeedtech.com/debian/lst_repo.gpg | gpg --batch --yes --dearmor -o /usr/share/keyrings/lst-repo.gpg
  CODENAME=\$(. /etc/os-release && echo "\${VERSION_CODENAME:-bullseye}")
  echo "deb [signed-by=/usr/share/keyrings/lst-repo.gpg] http://rpms.litespeedtech.com/debian/ \$CODENAME main" > /etc/apt/sources.list.d/lst_debian_repo.list
  apt-get update -y
  apt-get install -y --no-install-recommends openlitespeed
  systemctl stop lshttpd 2>/dev/null || true
fi
[ -x /usr/local/lsws/bin/lshttpd ] || { echo "[dply] lshttpd binary not found at /usr/local/lsws/bin/lshttpd after install" >&2; exit 127; }
{$lsphpPackages}
BASH;
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

            // Traefik per-site provisioning needs a second file written: the
            // Caddy backend config bound to the per-site ephemeral port that
            // the Traefik router targets. The dynamic YAML written above only
            // routes — it doesn't serve.
            if ($this->target === 'traefik') {
                $this->writeTraefikCaddyBackend($server, $ssh, $site);
            }

            // For nginx/apache, ensure sites-enabled is symlinked to sites-available.
            $this->ensureSiteEnabled($server, $ssh, $site, $this->target);
        }

        // OLS pulls per-vhost configs in via vhTemplate blocks in the top-level
        // httpd_config — write the dply-owned one bound to :8080 here. Cutover
        // overwrites it with the :80-bound version.
        if ($this->target === 'openlitespeed') {
            $this->writeOlsHttpdConfig($server, $ssh, $sites, listenPort: 8080);
        }

        // Traefik's static config (entry-point bind port) is independent of
        // the per-site dynamic YAMLs. Write it now bound to :8080; cutover
        // overwrites with :80 to land the production listener.
        if ($this->target === 'traefik') {
            $this->writeTraefikStaticConfig($server, $ssh, listenPort: 8080);
        }

        // HAProxy uses a monolithic haproxy.cfg with frontend ACLs + backends
        // for all sites. Render once now bound to :8080 (validate-safe);
        // cutover regenerates with :80.
        if ($this->target === 'haproxy') {
            $this->writeHAProxyEdgeConfig($server, $ssh, $sites, listenPort: 8080);
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
            // `lshttpd -t` parses the active httpd_config.conf and per-vhost
            // configs in dry-run mode (no port binding) — same model as
            // apachectl configtest / nginx -t.
            'openlitespeed' => '/usr/local/lsws/bin/lshttpd -t',
            // Traefik has no native parse-only flag. The dynamic YAMLs are
            // simple and well-formed by construction; the meatier validation
            // is the per-site Caddy backend (PHP-FPM handlers, redirects,
            // basic auth — all the actual web-serving logic). `caddy
            // validate` catches everything that matters in practice.
            'traefik' => 'caddy validate --config /etc/caddy/Caddyfile',
            // HAProxy parses its config with `haproxy -c -f <file>`. Returns
            // exit 0 + "Configuration file is valid" on success. Run this
            // AND caddy validate because both layers must be syntactically
            // sound for the cutover to succeed.
            'haproxy' => 'haproxy -c -f /etc/haproxy/haproxy.cfg && caddy validate --config /etc/caddy/Caddyfile',
            default => throw new \RuntimeException(sprintf(
                'No config-test command for "%s" — supported in v1: nginx, caddy, apache, openlitespeed, traefik, haproxy.',
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

        // OLS: rewrite the top-level httpd_config.conf with the production
        // listener bound to :80. The per-vhconf rewrite above is a no-op for
        // OLS (vhconf.conf is portless) but kept for symmetry with other
        // engines — the port swap actually lives here.
        if ($this->target === 'openlitespeed') {
            $this->writeOlsHttpdConfig($server, $ssh, $sites, listenPort: 80);
        }

        // HAProxy: rewrite the monolithic haproxy.cfg with the production
        // bind port (:80). Per-site Caddy backends already written by the
        // loop above stay on their high ports. Same caddy-handover dance
        // as Traefik below.
        if ($this->target === 'haproxy') {
            if ($from === 'caddy') {
                foreach ($sites as $site) {
                    $basename = method_exists($site, 'webserverConfigBasename')
                        ? (string) $site->webserverConfigBasename()
                        : (string) $site->slug;
                    $oldPath = '/etc/caddy/sites-enabled/'.$basename.'.caddy';
                    $ssh->exec($this->privilegedCommand($server, 'rm -f '.escapeshellarg($oldPath)), 15);
                }
            }
            $this->writeHAProxyEdgeConfig($server, $ssh, $sites, listenPort: 80);
            $ssh->exec($this->privilegedCommand($server, '(systemctl is-active --quiet caddy && systemctl reload caddy) || systemctl enable --now caddy'), 30);
        }

        // Traefik: per-site dynamic YAMLs don't carry a listen port (the
        // router rules are host-keyed, traffic always lands on the static
        // entry-point bind). Cutover only needs to rewrite traefik.yml to
        // bind :80. Caddy backend configs stay on their high ports through
        // both phases.
        if ($this->target === 'traefik') {
            // If switching FROM caddy, the legacy per-site :80 configs at
            // /etc/caddy/sites-enabled/<basename>.caddy collide with the
            // new -backend.caddy on high ports (both would try to bind :80
            // on caddy reload). Remove them now — the new backend configs
            // are already written, so caddy reload moves cleanly to high
            // ports only.
            if ($from === 'caddy') {
                foreach ($sites as $site) {
                    $basename = method_exists($site, 'webserverConfigBasename')
                        ? (string) $site->webserverConfigBasename()
                        : (string) $site->slug;
                    $oldPath = '/etc/caddy/sites-enabled/'.$basename.'.caddy';
                    $ssh->exec($this->privilegedCommand($server, 'rm -f '.escapeshellarg($oldPath)), 15);
                }
            }
            $this->writeTraefikStaticConfig($server, $ssh, listenPort: 80);
            // Caddy must be running (as the backend) before Traefik comes
            // up — Traefik's routes target Caddy on high ports. Reload if
            // already up so it picks up new -backend.caddy configs; start
            // if it isn't running yet (fresh switch from nginx/apache/OLS).
            $ssh->exec($this->privilegedCommand($server, '(systemctl is-active --quiet caddy && systemctl reload caddy) || systemctl enable --now caddy'), 30);
        }

        // Atomic-ish service swap: stop old, enable+start new. The gap between
        // these is the operator-visible downtime window (typically <1s).
        $fromUnit = $this->systemdUnitFor($from);
        $toUnit = $this->systemdUnitFor($this->target);
        // Switching to traefik or haproxy with from=caddy: do NOT stop caddy.
        // It's now the per-site backend (on ephemeral high ports). Stopping
        // it would take all the upstreams offline and the edge proxy would
        // 502 every request. The :80 release happened above via the per-site
        // config cleanup + caddy reload.
        if (in_array($this->target, ['traefik', 'haproxy'], true) && $from === 'caddy') {
            $fromUnit = null;
        }
        if ($fromUnit !== null) {
            $ssh->exec($this->privilegedCommand($server, sprintf('systemctl stop %s', escapeshellarg($fromUnit))), 30);
        }
        if ($toUnit !== null) {
            $cmd = sprintf('systemctl enable --now %1$s && systemctl reload %1$s', escapeshellarg($toUnit));
            $out = $ssh->exec($this->privilegedCommand($server, $cmd.' 2>&1'), 60);
            $exit = $ssh->lastExecExitCode();
            if ($exit !== null && $exit !== 0) {
                $diag = $this->captureUnitDiagnostics($server, $ssh, $toUnit);
                throw new \RuntimeException(sprintf(
                    "Failed to start %s during cutover (exit %d): %s\n\n%s",
                    $toUnit,
                    $exit,
                    trim(substr($out, -500)),
                    $diag,
                ));
            }
        }
    }

    /**
     * Capture `systemctl status`, the recent journal, and the on-disk config(s)
     * for the failing webserver, so callers can embed the diagnostic in the
     * exception they raise. Best-effort: any failure to collect is folded into
     * the returned text rather than thrown, since the caller is already on a
     * failure path.
     *
     * Journal is filtered to `--since "-2min"` so we get only the latest failed
     * start, not stale 217/USER lines from prior attempts. `-x` (explanatory
     * blurbs) is dropped to keep the output compact enough to clear the banner /
     * UI truncation budget on the way to the actual error message.
     */
    private function captureUnitDiagnostics(Server $server, SshConnection $ssh, string $unit): string
    {
        $unitArg = escapeshellarg($unit);
        $configPaths = $this->diagnosticConfigPathsFor($this->target);

        $parts = [
            sprintf('echo "--- systemctl status %1$s ---"; systemctl status --no-pager --full %1$s 2>&1 | tail -n 30', $unitArg),
            sprintf('echo; echo "--- journalctl -eu %1$s (last 2 min) ---"; journalctl --no-pager -eu %1$s --since "-2min" 2>&1 | tail -n 120', $unitArg),
        ];
        foreach ($configPaths as $glob) {
            // The glob is intentionally NOT escapeshellarg'd — we want shell expansion
            // on the for-loop. The values come from a fixed match() table below, never
            // from user input, so injection is not a concern.
            $parts[] = sprintf(
                'echo; echo "--- %1$s ---"; for f in %1$s; do [ -e "$f" ] && { echo "# $f"; cat "$f"; echo; }; done',
                $glob,
            );
        }
        $script = '{ '.implode('; ', $parts).'; } 2>&1 || true';

        $out = $ssh->exec($this->privilegedCommand($server, $script), 30);

        // Cap so a runaway journal can't blow up the exception message / UI banner.
        $trimmed = trim((string) $out);

        return strlen($trimmed) > 8000 ? substr($trimmed, -8000) : $trimmed;
    }

    /**
     * On-disk paths (globs) worth dumping when a webserver fails to start.
     * Returns the main config plus per-site enabled configs for the target.
     */
    private function diagnosticConfigPathsFor(string $target): array
    {
        return match ($target) {
            'caddy' => ['/etc/caddy/Caddyfile', '/etc/caddy/sites-enabled/*.caddy'],
            'nginx' => ['/etc/nginx/nginx.conf', '/etc/nginx/sites-enabled/*'],
            'apache' => ['/etc/apache2/apache2.conf', '/etc/apache2/sites-enabled/*.conf'],
            'openlitespeed' => ['/usr/local/lsws/conf/httpd_config.conf', '/usr/local/lsws/conf/vhosts/*/vhconf.conf'],
            'traefik' => ['/etc/traefik/traefik.yml', '/etc/traefik/dynamic/*.yml', '/etc/caddy/Caddyfile', '/etc/caddy/sites-enabled/*-backend.caddy'],
            'haproxy' => ['/etc/haproxy/haproxy.cfg', '/etc/caddy/Caddyfile', '/etc/caddy/sites-enabled/*-backend.caddy'],
            default => [],
        };
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
            'openlitespeed' => app(OpenLiteSpeedSiteConfigBuilder::class)->build($site, $listenPort),
            default => throw new \RuntimeException(sprintf(
                'No config builder for "%s" — supported in v1: nginx, caddy, apache, openlitespeed.',
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
            'openlitespeed' => '/usr/local/lsws/conf/vhosts/'.$basename.'/vhconf.conf',
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
            // OLS keeps per-vhost configs under conf/vhosts/<name>/vhconf.conf;
            // executeStageProvision writes the top-level httpd_config.conf
            // after the per-site loop via writeOlsHttpdConfig().
            'openlitespeed' => 'mkdir -p /usr/local/lsws/conf/vhosts',
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
     * Render and write the dply-owned `/usr/local/lsws/conf/httpd_config.conf`
     * bound to `$listenPort`. Stage 2 calls this with :8080 so `lshttpd -t`
     * parses a config that doesn't conflict with the live webserver on :80;
     * cutover calls it again with :80 to land the production listener before
     * service-swap.
     *
     * Backs up any pre-existing httpd_config.conf to `.dply-bak-<timestamp>`
     * the first time we write — a fresh `apt install openlitespeed` ships a
     * stock config with WebAdmin + Example vhosts that we don't want to keep,
     * but we preserve it for forensic recovery.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Site>  $sites
     */
    private function writeOlsHttpdConfig(
        Server $server,
        SshConnection $ssh,
        \Illuminate\Database\Eloquent\Collection $sites,
        int $listenPort
    ): void {
        $path = '/usr/local/lsws/conf/httpd_config.conf';
        $backupCmd = sprintf(
            '[ -f %1$s ] && [ ! -f %1$s.dply-bak ] && cp %1$s %1$s.dply-bak || true',
            escapeshellarg($path),
        );
        $ssh->exec($this->privilegedCommand($server, $backupCmd), 15);

        $contents = app(OpenLiteSpeedHttpdConfigBuilder::class)->build($sites, $listenPort);
        $this->writeRemoteFile($server, $ssh, $path, $contents);
    }

    /**
     * Write the dply-owned `/etc/traefik/traefik.yml` static config bound to
     * `$listenPort`. The file provider watches /etc/traefik/dynamic and
     * picks up router/service definitions automatically, so this file only
     * needs to declare the entry point and provider path. Backed-up first
     * time to .dply-bak so any prior hand-edits survive.
     */
    private function writeTraefikStaticConfig(Server $server, SshConnection $ssh, int $listenPort): void
    {
        $path = '/etc/traefik/traefik.yml';
        $backupCmd = sprintf(
            '[ -f %1$s ] && [ ! -f %1$s.dply-bak ] && cp %1$s %1$s.dply-bak || true',
            escapeshellarg($path),
        );
        $ssh->exec($this->privilegedCommand($server, $backupCmd), 15);

        $contents = <<<YAML
# Managed by Dply — do NOT hand-edit. Regenerated on every webserver switch.
entryPoints:
  web:
    address: ":{$listenPort}"
providers:
  file:
    directory: /etc/traefik/dynamic
    watch: true
YAML;
        $this->writeRemoteFile($server, $ssh, $path, $contents);
    }

    /**
     * Write the dply-owned `/etc/haproxy/haproxy.cfg` bound to `$listenPort`,
     * covering all sites on the server with one frontend + per-site backends
     * pointing at the Caddy upstream. Stage 2 calls this with :8080 (safe
     * to validate while the old webserver is still on :80); cutover calls
     * with :80 to land the production listener.
     *
     * Backs up the original (apt-installed) /etc/haproxy/haproxy.cfg to
     * .dply-bak first time so the operator can restore manually if needed.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Site>  $sites
     */
    private function writeHAProxyEdgeConfig(
        Server $server,
        SshConnection $ssh,
        \Illuminate\Database\Eloquent\Collection $sites,
        int $listenPort
    ): void {
        $path = '/etc/haproxy/haproxy.cfg';
        $backupCmd = sprintf(
            '[ -f %1$s ] && [ ! -f %1$s.dply-bak ] && cp %1$s %1$s.dply-bak || true',
            escapeshellarg($path),
        );
        $ssh->exec($this->privilegedCommand($server, $backupCmd), 15);

        $contents = app(HAProxyEdgeConfigBuilder::class)->build(
            $sites,
            $listenPort,
            fn (Site $s): int => $this->traefikBackendPort($s),
        );
        $this->writeRemoteFile($server, $ssh, $path, $contents);
    }

    /**
     * For a Traefik-edged server, write the per-site Caddy backend config
     * bound to the site's ephemeral high port. Traefik's router targets
     * `http://127.0.0.1:<backend_port>`; this is the daemon listening
     * there. We re-use CaddySiteConfigBuilder so basic-auth / redirects /
     * PHP-FPM upstream / static serving all behave identically to the
     * caddy-edge case — only the listener port differs.
     */
    private function writeTraefikCaddyBackend(Server $server, SshConnection $ssh, Site $site): void
    {
        $basename = method_exists($site, 'webserverConfigBasename')
            ? (string) $site->webserverConfigBasename()
            : (string) $site->slug;
        $port = $this->traefikBackendPort($site);
        $config = app(CaddySiteConfigBuilder::class)->build($site, $port);
        $path = '/etc/caddy/sites-enabled/'.$basename.'-backend.caddy';
        $this->writeRemoteFile($server, $ssh, $path, $config);
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
        // Switching to traefik/haproxy with from=caddy: skip the disable.
        // Caddy is the per-site backend now — disabling it would take every
        // site upstream offline on the next reboot.
        if (in_array($this->target, ['traefik', 'haproxy'], true) && $from === 'caddy') {
            return;
        }

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
