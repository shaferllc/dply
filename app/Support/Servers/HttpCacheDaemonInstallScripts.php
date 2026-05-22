<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\ServerCacheService;

/**
 * Install / uninstall bash + systemd-unit + config-path helpers for HTTP-front
 * cache daemons (Varnish today). Sibling to {@see CacheServiceInstallScripts}
 * — kept separate because the install model is different: HTTP-front daemons
 * own port 80 and require the backend webserver to be reconfigured to listen
 * on 127.0.0.1:8080, so install/uninstall pair with a backend-port flip.
 *
 * The version-probe + parse helpers reuse the same shape as
 * `CacheServiceInstallScripts::parseVersionFromBuffer()` so the install job
 * can stay engine-agnostic.
 */
final class HttpCacheDaemonInstallScripts
{
    /** @var list<string> */
    public const SUPPORTED_ENGINES = ['varnish'];

    /**
     * Idempotent apt-install + VCL deploy + systemd-enable for an HTTP-front
     * daemon. The bash script is composed in three stages so a re-run after a
     * partial failure (apt installed but VCL missing) heals cleanly.
     */
    public static function installScriptForRow(ServerCacheService $row, int $backendPort = 8080): string
    {
        return match ($row->engine) {
            'varnish' => self::varnishInstallScript($backendPort),
            default => throw new \InvalidArgumentException("Unsupported HTTP-front engine: {$row->engine}"),
        };
    }

    public static function uninstallScript(string $engine): string
    {
        return match ($engine) {
            'varnish' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
systemctl disable --now varnish || true
apt-get purge -y varnish || true
apt-get autoremove -y
rm -f /etc/systemd/system/varnish.service.d/dply-listener.conf
systemctl daemon-reload || true
BASH,
            default => throw new \InvalidArgumentException("Unsupported HTTP-front engine: {$engine}"),
        };
    }

    public static function versionProbeScript(string $engine): string
    {
        return match ($engine) {
            'varnish' => '(command -v varnishd >/dev/null 2>&1 && varnishd -V 2>&1 | head -n1) || true',
            default => 'true',
        };
    }

    public static function systemdServiceFor(string $engine): string
    {
        return match ($engine) {
            'varnish' => 'varnish',
            default => throw new \InvalidArgumentException("Unsupported HTTP-front engine: {$engine}"),
        };
    }

    /**
     * Idempotent Varnish install. Pins the listener override to *:80 via a
     * systemd drop-in (rather than editing /lib/systemd/system/varnish.service
     * directly, which apt-upgrade would overwrite). Renders a minimal default
     * VCL that points at 127.0.0.1:$backendPort and exposes a localhost-only
     * purge ACL.
     */
    private static function varnishInstallScript(int $backendPort): string
    {
        $port = max(1, $backendPort);
        $cacheSizeMb = max(64, (int) config('server_cache.varnish_cache_size_mb', 256));
        $vcl = self::renderDefaultVcl($port);

        // shellwords-safe: $port comes from a typed int, $cacheSizeMb same.
        return <<<BASH
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

if ! command -v varnishd >/dev/null 2>&1; then
    apt-get update -y
    apt-get install -y varnish
fi
command -v varnishd >/dev/null 2>&1 || { echo "ERROR: varnishd binary not on PATH after apt install." >&2; exit 1; }

# systemd drop-in: override the listener to *:80 + set the cache size from
# our config without modifying the package-shipped unit file (which apt would
# clobber on upgrade).
mkdir -p /etc/systemd/system/varnish.service.d
cat > /etc/systemd/system/varnish.service.d/dply-listener.conf <<'DROPIN'
[Service]
ExecStart=
ExecStart=/usr/sbin/varnishd -j unix,user=vcache -F -a :80 -T localhost:6082 -f /etc/varnish/default.vcl -S /etc/varnish/secret -s malloc,{$cacheSizeMb}m
DROPIN

# Render the default VCL atomically — write to a tmp path then mv. Avoids
# leaving a half-written file under /etc/varnish/ if the script is killed
# mid-write.
cat > /etc/varnish/default.vcl.dply.tmp <<'DPLY_VCL_EOF'
{$vcl}
DPLY_VCL_EOF
mv /etc/varnish/default.vcl.dply.tmp /etc/varnish/default.vcl
chown root:root /etc/varnish/default.vcl
chmod 0644 /etc/varnish/default.vcl

systemctl daemon-reload
systemctl enable --now varnish
systemctl is-active varnish >/dev/null

# Smoke test: hit the listener directly. If the backend isn't up yet,
# Varnish still answers (with a 503 from `vcl_backend_error`), and we
# want to surface a connection-refused from Varnish itself (which is a
# real error) vs a 503 from the backend (which is fine pre-cutover).
if command -v curl >/dev/null 2>&1; then
    curl -fsS --max-time 5 -o /dev/null -w '%{http_code}\\n' http://127.0.0.1:80/__dply_varnish_probe || true
fi
BASH;
    }

    /**
     * Minimal Varnish 7+ VCL. Points at `127.0.0.1:$backendPort`, exposes
     * `PURGE` from localhost only, and adds a small set of "don't cache"
     * heuristics (cookies, auth header, non-GET/HEAD methods) — operators
     * who need more nuance edit the file via the workspace VCL editor.
     */
    private static function renderDefaultVcl(int $backendPort): string
    {
        return <<<VCL
vcl 4.1;

backend default {
    .host = "127.0.0.1";
    .port = "{$backendPort}";
    .connect_timeout = 5s;
    .first_byte_timeout = 30s;
    .between_bytes_timeout = 10s;
}

acl purge {
    "127.0.0.1";
    "::1";
}

sub vcl_recv {
    if (req.method == "PURGE") {
        if (!client.ip ~ purge) {
            return (synth(405, "Not allowed."));
        }
        return (purge);
    }

    # Pass-through for write methods and authenticated requests.
    if (req.method != "GET" && req.method != "HEAD") {
        return (pass);
    }
    if (req.http.Authorization) {
        return (pass);
    }
    if (req.http.Cookie ~ "(PHPSESSID|wordpress_logged_in_|laravel_session|sails\\.sid)") {
        return (pass);
    }

    return (hash);
}

sub vcl_backend_response {
    # Default object TTL — operators override per-site via Cache-Control.
    if (beresp.ttl <= 0s) {
        set beresp.ttl = 120s;
    }
    return (deliver);
}

sub vcl_deliver {
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
    } else {
        set resp.http.X-Cache = "MISS";
    }
    set resp.http.X-Dply-Varnish = "1";
    return (deliver);
}
VCL;
    }
}
