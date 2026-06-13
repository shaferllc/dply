<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Jobs\AddEdgeProxyJob;
use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\OpenLiteSpeedHttpdConfigBuilder;
use App\Services\Servers\OpenLiteSpeedHttpdConfigPreserver;
use App\Services\Servers\WebserverStatsEndpointTemplates;
use App\Services\SshConnection;
use App\Support\Servers\CaddyRuntimeOwnership;
use Illuminate\Database\Eloquent\Collection;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsWebserverInstallScripts
{


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
            'nginx' => $this->aptInstallIdempotent('nginx').'; '.$this->nginxStatsEndpointPatch(),
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
            'apache' => $this->aptInstallIdempotent('apache2')
                .'; apt-get install -y --no-install-recommends certbot python3-certbot-apache'
                .'; '.$this->apacheInstallPostPatches(),
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
     * Post-apt-install patches for apache: a2enmod the modules dply's
     * vhost templates assume (proxy*, rewrite, headers, status), drop the
     * ServerName conf to silence AH00558, and write+enable the
     * mod_status localhost-only endpoint dply's metrics agent scrapes.
     * Sourced from {@see WebserverStatsEndpointTemplates::apacheServerStatusConf()}
     * so the backfill command and install path share one template body.
     */
    private function apacheInstallPostPatches(): string
    {
        $statusBody = WebserverStatsEndpointTemplates::apacheServerStatusConf();
        $statusPath = WebserverStatsEndpointTemplates::APACHE_CONF_PATH;
        $statusName = WebserverStatsEndpointTemplates::APACHE_CONF_NAME;

        return <<<BASH
a2enmod proxy proxy_http proxy_fcgi rewrite headers status ssl >/dev/null
DPLY_FQDN="\$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo localhost)"
printf 'ServerName %s\n' "\$DPLY_FQDN" > /etc/apache2/conf-available/dply-servername.conf
a2enconf dply-servername >/dev/null
cat > {$statusPath} <<'CONF'
{$statusBody}
CONF
a2enconf {$statusName} >/dev/null
BASH;
    }

    /**
     * Localhost-only nginx stub_status endpoint. Body sourced from
     * {@see WebserverStatsEndpointTemplates::nginxStubStatusConf()} so the
     * backfill command and the install script share one template.
     */
    private function nginxStatsEndpointPatch(): string
    {
        $body = WebserverStatsEndpointTemplates::nginxStubStatusConf();
        $path = WebserverStatsEndpointTemplates::NGINX_CONF_PATH;

        return sprintf(
            "cat > %s <<'CONF'\n%s\nCONF\n",
            $path,
            $body,
        );
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

BASH
            .CaddyRuntimeOwnership::shell();
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
DPLY_ARCH=$(uname -m)
case "$DPLY_ARCH" in
  x86_64|amd64) DPLY_ARCH=amd64 ;;
  aarch64|arm64) DPLY_ARCH=arm64 ;;
  armv7l) DPLY_ARCH=armv7 ;;
  *) echo "[dply] unsupported arch: $DPLY_ARCH" >&2; exit 127 ;;
esac

if [ -x /usr/local/bin/traefik ] && systemctl list-unit-files | grep -q '^traefik\.service'; then
  echo "[dply] traefik already installed; skipping."
else
  apt-get install -y --no-install-recommends curl ca-certificates
  TRAEFIK_VERSION="${TRAEFIK_VERSION:-v3.1.0}"
  TRAEFIK_URL="https://github.com/traefik/traefik/releases/download/${TRAEFIK_VERSION}/traefik_${TRAEFIK_VERSION}_linux_${DPLY_ARCH}.tar.gz"
  echo "[dply] downloading traefik ${TRAEFIK_VERSION} (linux/${DPLY_ARCH})…"
  curl -fSL "$TRAEFIK_URL" -o /tmp/traefik.tgz
  echo "[dply] extracting traefik binary…"
  rm -rf /tmp/traefik-extract
  mkdir -p /tmp/traefik-extract
  tar -xzf /tmp/traefik.tgz -C /tmp/traefik-extract
  TRAEFIK_BIN=$(find /tmp/traefik-extract -type f -name traefik | head -n 1)
  if [ -z "$TRAEFIK_BIN" ] || [ ! -f "$TRAEFIK_BIN" ]; then
    echo "[dply] traefik binary not found in tarball; archive contents:" >&2
    find /tmp/traefik-extract -maxdepth 3 -ls >&2
    exit 127
  fi
  install -m 0755 "$TRAEFIK_BIN" /usr/local/bin/traefik
  rm -rf /tmp/traefik.tgz /tmp/traefik-extract
  echo "[dply] traefik installed at /usr/local/bin/traefik"
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
# Traefik reloads dynamic provider configs (file/Docker/Consul/etc.) on
# SIGHUP — that covers /etc/traefik/dynamic/*.yml edits without dropping
# active connections. Static-config (traefik.yml) changes still require
# a full restart since the binary parses it once at startup.
ExecReload=/bin/kill -HUP $MAINPID
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
[ -x /usr/local/bin/traefik ] || { echo "[dply] traefik binary missing at /usr/local/bin/traefik" >&2; exit 127; }
[ -f /etc/systemd/system/traefik.service ] || { echo "[dply] traefik.service file missing at /etc/systemd/system/traefik.service" >&2; exit 127; }
systemctl cat traefik.service >/dev/null 2>&1 || { echo "[dply] traefik.service not picked up by systemd; daemon-reload + cat failed" >&2; systemctl cat traefik.service >&2 || true; exit 127; }
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
apt-get install -y --no-install-recommends certbot || true
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

    private function writeOlsHttpdConfig(
        Server $server,
        SshConnection $ssh,
        Collection $sites,
        int $listenPort
    ): void {
        $path = '/usr/local/lsws/conf/httpd_config.conf';
        $backupCmd = sprintf(
            '[ -f %1$s ] && [ ! -f %1$s.dply-bak ] && cp %1$s %1$s.dply-bak || true',
            escapeshellarg($path),
        );
        $ssh->exec($this->privilegedCommand($server, $backupCmd), 15);

        $contents = app(OpenLiteSpeedHttpdConfigBuilder::class)->build($sites, $listenPort);
        $existing = $ssh->exec('sudo -n cat '.escapeshellarg($path).' 2>/dev/null', 15);
        if ($existing !== '' && $ssh->lastExecExitCode() === 0) {
            $contents = app(OpenLiteSpeedHttpdConfigPreserver::class)->merge($contents, $existing);
        }
        $this->writeRemoteFile($server, $ssh, $path, $contents);
    }
}
