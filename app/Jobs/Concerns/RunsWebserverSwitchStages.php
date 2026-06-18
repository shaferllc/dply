<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Server;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;
use App\Support\Servers\CaddyRuntimeOwnership;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait RunsWebserverSwitchStages
{


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

            if ($this->target === 'openlitespeed') {
                $repo = rtrim($site->effectiveRepositoryPath(), '/');
                $ssh->exec($this->privilegedCommand($server, 'mkdir -p '.escapeshellarg($repo.'/logs')), 15);
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

        if ($this->target === 'caddy') {
            $this->ensureCaddyRuntimeOwnership($server, $ssh);
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
            'caddy' => CaddyRuntimeOwnership::validateCommand(),
            'apache' => 'apachectl configtest',
            // `lshttpd -t` parses the active httpd_config.conf and per-vhost
            // configs in dry-run mode (no port binding) — same model as
            // apachectl configtest / nginx -t.
            'openlitespeed' => '/usr/local/lsws/bin/lshttpd -t',
            default => throw new \RuntimeException(sprintf(
                'No config-test command for "%s" — supported in v1: nginx, caddy, apache, openlitespeed.',
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

        if ($this->target === 'caddy') {
            $this->ensureCaddyRuntimeOwnership($server, $ssh);
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

        // Atomic-ish service swap: stop old, wait for :80 to actually be free,
        // then enable+start new. The gap between stop and start is the
        // operator-visible downtime window (typically <1s).
        //
        // Why the wait: `systemctl stop` returns once the unit hits inactive,
        // but the kernel can hold the socket in TIME_WAIT or the daemon's
        // child workers (OLS lsphpXX, Apache children) may linger fractionally
        // longer. Caddy/nginx/Apache then refuse to start with
        // `bind: address already in use`. Polling for the port to drop is
        // cheap insurance — we only loop while a listener is there.
        $fromUnit = $this->systemdUnitFor($from);
        $toUnit = $this->systemdUnitFor($this->target);
        if ($fromUnit !== null) {
            $ssh->exec($this->privilegedCommand($server, sprintf('systemctl stop %s', escapeshellarg($fromUnit))), 30);
            $this->waitForPortFree($server, $ssh, 80, $fromUnit);
        }
        if ($toUnit !== null) {
            if ($this->target === 'caddy') {
                $this->ensureCaddyRuntimeOwnership($server, $ssh);
            }
            // `restart` (not `enable --now` + `reload`): a failed first start leaves
            // the unit inactive and `reload` errors with "cannot reload".
            $cmd = sprintf(
                'systemctl enable %1$s 2>/dev/null || true; systemctl restart %1$s 2>&1; systemctl is-active %1$s',
                escapeshellarg($toUnit),
            );
            $out = $ssh->exec($this->privilegedCommand($server, $cmd), 60);
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
}
