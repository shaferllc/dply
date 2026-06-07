<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\ServerLogAgent;

/**
 * Builds the bash that installs/uninstalls the dply Logs edge agent (Vector) on a
 * managed box, plus the rendered vector.toml. Mirrors the shape of
 * {@see HttpCacheDaemonInstallScripts} / {@see CacheServiceInstallScripts}: pure
 * string builders, no SSH — {@see \App\Jobs\InstallLogAgentJob} runs the output.
 *
 * Install strategy: Vector ships a single static binary, so we fetch the pinned
 * release tarball, verify its SHA-256, and drop the binary at /usr/local/bin/
 * dply-vector (namespaced so we never collide with an operator-installed vector).
 * Config lives at /etc/dply-logship/vector.toml; the disk buffer + state live at
 * /var/lib/dply-logship. A systemd unit (resources/server-scripts/dply-logship.service)
 * supervises it with hard CPU/memory ceilings.
 *
 * See docs/SERVER_LOGS_ADDON.md.
 */
class VectorLogAgentInstallScripts
{
    public const BINARY_PATH = '/usr/local/bin/dply-vector';

    public const CONFIG_DIR = '/etc/dply-logship';

    public const CONFIG_PATH = '/etc/dply-logship/vector.toml';

    public const DATA_DIR = '/var/lib/dply-logship';

    public const UNIT_NAME = 'dply-logship.service';

    public const UNIT_PATH = '/etc/systemd/system/dply-logship.service';

    /**
     * Full install bash: fetch + verify Vector, write config + unit, enable/start,
     * then print the version for the job to parse. Idempotent — re-running after a
     * version bump re-verifies the binary and restarts the unit.
     */
    public function installScript(ServerLogAgent $agent): string
    {
        $version = (string) config('server_logs.vector_version', '0.48.0');
        $sha = trim((string) config('server_logs.vector_sha256', ''));
        $url = $this->tarballUrl($version);

        $configB64 = base64_encode($this->renderVectorToml($agent));
        $unitB64 = base64_encode($this->renderSystemdUnit());

        $shaCheck = $sha !== ''
            ? <<<BASH
            echo "{$sha}  \$TMP_TGZ" | sha256sum -c - || { echo "vector tarball sha mismatch"; exit 1; }
            BASH
            : 'echo "WARN: vector sha256 not pinned — skipping integrity check (dev only)"';

        return <<<BASH
        export DEBIAN_FRONTEND=noninteractive

        # --- prerequisites -------------------------------------------------------
        if ! command -v curl >/dev/null 2>&1; then
          apt-get update -y >/dev/null 2>&1 || true
          apt-get install -y curl ca-certificates >/dev/null 2>&1 || true
        fi

        ARCH="\$(uname -m)"
        case "\$ARCH" in
          x86_64|amd64) VEC_ARCH="x86_64-unknown-linux-musl" ;;
          aarch64|arm64) VEC_ARCH="aarch64-unknown-linux-musl" ;;
          *) echo "unsupported arch: \$ARCH"; exit 1 ;;
        esac

        # --- fetch + verify Vector ----------------------------------------------
        NEED_INSTALL=1
        if [ -x "{$this->binaryPath()}" ]; then
          CUR="\$({$this->binaryPath()} --version 2>/dev/null | awk '{print \$2}' || true)"
          [ "\$CUR" = "{$version}" ] && NEED_INSTALL=0
        fi

        if [ "\$NEED_INSTALL" = "1" ]; then
          TMP_TGZ="\$(mktemp)"
          URL="\$(echo "{$url}" | sed "s/__ARCH__/\$VEC_ARCH/")"
          curl -fsSL --retry 3 -o "\$TMP_TGZ" "\$URL" || { echo "vector download failed: \$URL"; rm -f "\$TMP_TGZ"; exit 1; }
          {$shaCheck}
          TMP_DIR="\$(mktemp -d)"
          tar -xzf "\$TMP_TGZ" -C "\$TMP_DIR" || { echo "vector extract failed"; rm -rf "\$TMP_TGZ" "\$TMP_DIR"; exit 1; }
          VEC_BIN="\$(find "\$TMP_DIR" -type f -name vector -perm -u+x | head -n1)"
          [ -n "\$VEC_BIN" ] || { echo "vector binary not found in tarball"; rm -rf "\$TMP_TGZ" "\$TMP_DIR"; exit 1; }
          install -m 0755 "\$VEC_BIN" "{$this->binaryPath()}"
          rm -rf "\$TMP_TGZ" "\$TMP_DIR"
        fi

        # --- directories ---------------------------------------------------------
        install -d -m 0750 "{$this->configDir()}"
        install -d -m 0750 "{$this->dataDir()}"

        # --- mTLS material (ca/client cert+key for shipping to the aggregator) ----
        {$this->certDeployFragment()}

        # --- config --------------------------------------------------------------
        echo "{$configB64}" | base64 -d > "{$this->configPath()}"
        chmod 0640 "{$this->configPath()}"

        # Validate config before we (re)start so a bad render fails loudly here
        # instead of crash-looping the unit.
        "{$this->binaryPath()}" validate "{$this->configPath()}" || { echo "vector config validation failed"; exit 1; }

        # --- systemd unit --------------------------------------------------------
        echo "{$unitB64}" | base64 -d > "{$this->unitPath()}"
        chmod 0644 "{$this->unitPath()}"
        systemctl daemon-reload
        systemctl enable --now {$this->unitName()}
        systemctl restart {$this->unitName()}

        # --- report --------------------------------------------------------------
        sleep 1
        systemctl is-active --quiet {$this->unitName()} || { echo "dply-logship failed to start"; systemctl status {$this->unitName()} --no-pager -l | tail -n 30; exit 1; }
        echo "dply-logship installed and running"
        {$this->binaryPath()} --version
        BASH;
    }

    /**
     * Stop + remove the agent, its config, unit, and state. Idempotent.
     */
    public function uninstallScript(): string
    {
        return <<<BASH
        systemctl disable --now {$this->unitName()} 2>/dev/null || true
        rm -f "{$this->unitPath()}"
        systemctl daemon-reload 2>/dev/null || true
        rm -rf "{$this->configDir()}" "{$this->dataDir()}"
        rm -f "{$this->binaryPath()}"
        echo "dply-logship uninstalled"
        BASH;
    }

    /**
     * Bash that writes the mTLS material (CA + client cert/key) to the edge's
     * config dir, base64-decoded from config. When no aggregator endpoint is set
     * it's a no-op (edge ships to blackhole). When an endpoint IS set but material
     * is missing, the install fails loudly rather than rendering a sink that points
     * at certs that will never exist. The base64 keeps the (sensitive) key out of
     * argv and multi-line escaping; it travels inside the SSH'd script, not logs.
     */
    protected function certDeployFragment(): string
    {
        $endpoint = trim((string) config('server_logs.aggregator_endpoint', ''));
        if ($endpoint === '') {
            return ': # no aggregator endpoint configured — edge ships to blackhole, no certs needed';
        }

        $ca = trim((string) config('server_logs.mtls.ca_cert_b64', ''));
        $crt = trim((string) config('server_logs.mtls.client_cert_b64', ''));
        $key = trim((string) config('server_logs.mtls.client_key_b64', ''));

        if ($ca === '' || $crt === '' || $key === '') {
            return 'echo "dply Logs: aggregator endpoint set but mTLS material (SERVER_LOGS_*_B64) is missing"; exit 1';
        }

        $dir = $this->configDir();

        return <<<BASH
        echo "{$ca}" | base64 -d > "{$dir}/ca.crt"
        echo "{$crt}" | base64 -d > "{$dir}/client.crt"
        echo "{$key}" | base64 -d > "{$dir}/client.key"
        chmod 0640 "{$dir}/ca.crt" "{$dir}/client.crt" "{$dir}/client.key"
        test -s "{$dir}/client.key" || { echo "dply Logs: client.key empty after deploy"; exit 1; }
        BASH;
    }

    /**
     * Parse the Vector version from install output (`vector 0.48.0 (...)`).
     */
    public function parseVersion(string $buffer): ?string
    {
        if (preg_match('/vector\s+(\d+\.\d+\.\d+)/i', $buffer, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Render vector.toml for this agent's enabled sources. Pure string — no IO.
     */
    public function renderVectorToml(ServerLogAgent $agent): string
    {
        $active = $agent->activeSourceKeys();
        $serverId = (string) $agent->server_id;

        $blocks = [
            '# Rendered by dply — do not edit by hand. Managed by the dply Logs add-on.',
            'data_dir = "'.$this->dataDir().'"',
            '',
        ];

        $sourceNames = [];
        foreach ($active as $key) {
            $fragment = $this->sourceFragment($key);
            if ($fragment === null) {
                continue;
            }
            [$name, $toml] = $fragment;
            $sourceNames[] = $name;
            $blocks[] = $toml;
            $blocks[] = '';
        }

        // Nothing enabled → a minimal valid config that ships nothing. Keeps the
        // unit healthy rather than crash-looping on an empty pipeline.
        if ($sourceNames === []) {
            $blocks[] = "[sources.heartbeat]\ntype = \"internal_metrics\"";
            $blocks[] = '';
            $sourceNames[] = 'heartbeat';
        }

        // --- enrich: always stamp tenant identity (dply renders these server-side,
        //     so they're trusted-ish) + optional edge redaction ------------------
        $orgId = (string) ($agent->server?->organization_id ?? '');
        $blocks[] = $this->enrichTransform($sourceNames, $serverId, $orgId);
        $blocks[] = '';
        $sinkInput = ['enrich'];

        // --- sink ----------------------------------------------------------------
        $blocks[] = $this->sinkBlock($sinkInput);
        $blocks[] = '';

        return implode("\n", $blocks);
    }

    /**
     * Render the systemd unit from the bundled template, injecting resource caps.
     */
    public function renderSystemdUnit(): string
    {
        $template = (string) file_get_contents($this->unitTemplatePath());
        $cpu = (int) config('server_logs.limits.cpu_quota_percent', 15);
        $mem = (string) config('server_logs.limits.memory_max', '128M');

        return str_replace(
            ['__CPU_QUOTA__', '__MEMORY_MAX__'],
            [$cpu.'%', $mem],
            str_replace("\r\n", "\n", $template),
        );
    }

    protected function unitTemplatePath(): string
    {
        return resource_path('server-scripts/dply-logship.service');
    }

    protected function tarballUrl(string $version): string
    {
        // Official static release; __ARCH__ is substituted on the box from uname.
        return "https://packages.timber.io/vector/{$version}/vector-{$version}-__ARCH__.tar.gz";
    }

    /**
     * Per-source vector.toml fragment. Returns [sourceName, toml] or null for an
     * unknown key. Paths follow dply conventions (sites at /home/<user>/<domain>,
     * Laravel logs under storage/logs).
     *
     * @return array{0:string,1:string}|null
     */
    protected function sourceFragment(string $key): ?array
    {
        $deployUser = (string) config('server_logs.deploy_user', 'dply');
        $siteRoot = '/home/'.$deployUser;

        return match ($key) {
            'journald' => ['journald', <<<TOML
            [sources.journald]
            type = "journald"
            current_boot_only = false
            TOML],

            'web' => ['web', <<<TOML
            [sources.web]
            type = "file"
            include = ["/var/log/nginx/*.log", "/var/log/caddy/*.log"]
            ignore_older_secs = 600
            TOML],

            'php_fpm' => ['php_fpm', <<<TOML
            [sources.php_fpm]
            type = "file"
            include = ["/var/log/php*-fpm.log", "/var/log/php/*.log"]
            ignore_older_secs = 600
            TOML],

            'site_app' => ['site_app', <<<TOML
            [sources.site_app]
            type = "file"
            include = ["{$siteRoot}/*/storage/logs/*.log", "{$siteRoot}/*/current/storage/logs/*.log"]
            ignore_older_secs = 600
            TOML],

            'auth' => ['auth', <<<TOML
            [sources.auth]
            type = "file"
            include = ["/var/log/auth.log"]
            ignore_older_secs = 600
            TOML],

            default => null,
        };
    }

    /**
     * VRL transform: stamp tenant identity (server_id + org_id, both known to dply
     * at render time) and — when enabled — scrub common secret patterns at the edge
     * before logs leave the box. The aggregator consumes .dply_org_id/.dply_server_id
     * as the tenant key; per-server-cert-derived identity (closing the spoof gap) is
     * a later hardening step. Identity stamping is INDEPENDENT of redaction.
     *
     * VRL regex literals are single-quoted raw strings (r'...'), so patterns avoid
     * embedding a single-quote. Built line-by-line (single-quoted PHP) to keep `\s`
     * and `$1` capture refs intact without escaping gymnastics.
     *
     * @param  list<string>  $inputs
     */
    protected function enrichTransform(array $inputs, string $serverId, string $orgId): string
    {
        $inputsToml = $this->tomlStringArray($inputs);

        $lines = [
            '.dply_server_id = "'.$serverId.'"',
            '.dply_org_id = "'.$orgId.'"',
        ];

        if ((bool) config('server_logs.redaction.enabled', true)) {
            $lines[] = 'if is_string(.message) {';
            $lines[] = '  msg = string!(.message)';
            // Vector does env-var interpolation on config ($VAR), so VRL capture
            // refs must be escaped as $$1/$$2 to survive as literal $1/$2.
            $lines[] = '  msg = replace(msg, r\'(?i)(authorization: *bearer +)[A-Za-z0-9._~+/=-]+\', "$$1[REDACTED]")';
            $lines[] = '  msg = replace(msg, r\'AKIA[0-9A-Z]{16}\', "[REDACTED_AWS_KEY]")';
            $lines[] = '  msg = replace(msg, r\'(?i)(password|passwd|secret|token|api[_-]?key)(\s*[:=]\s*)\S+\', "$$1$$2[REDACTED]")';
            $lines[] = '  msg = replace(msg, r\'[a-zA-Z][a-zA-Z0-9+.-]*://[^:@/\s]+:[^@/\s]+@\', "[REDACTED_CREDS]@")';
            if ((bool) config('server_logs.redaction.redact_ips', false)) {
                $lines[] = '  msg = replace(msg, r\'\b(\d{1,3}\.){3}\d{1,3}\b\', "[REDACTED_IP]")';
            }
            $lines[] = '  .message = msg';
            $lines[] = '}';
        }

        $source = implode("\n", $lines);

        return <<<TOML
        [transforms.enrich]
        type = "remap"
        inputs = {$inputsToml}
        source = '''
        {$source}
        '''
        TOML;
    }

    /**
     * Sink block. When an aggregator endpoint is configured, ship over the Vector
     * protocol with mTLS; otherwise blackhole so the agent installs + runs healthy
     * for fake-cloud / pre-aggregator testing.
     *
     * @param  list<string>  $inputs
     */
    protected function sinkBlock(array $inputs): string
    {
        $inputsToml = $this->tomlStringArray($inputs);
        $endpoint = trim((string) config('server_logs.aggregator_endpoint', ''));
        $bufferBytes = (int) config('server_logs.limits.disk_buffer_max_bytes', 512 * 1024 * 1024);

        if ($endpoint === '') {
            return <<<TOML
            [sinks.dply]
            type = "blackhole"
            inputs = {$inputsToml}
            print_interval_secs = 0
            TOML;
        }

        // Vector's disk buffer is bounded at max_size; it has no "drop_oldest", so
        // we use drop_newest — disk stays capped (the goal: never fill the customer's
        // disk during a dply outage); only which events drop differs.
        return <<<TOML
        [sinks.dply]
        type = "vector"
        inputs = {$inputsToml}
        address = "{$endpoint}"

        [sinks.dply.tls]
        enabled = true
        ca_file = "{$this->configDir()}/ca.crt"
        crt_file = "{$this->configDir()}/client.crt"
        key_file = "{$this->configDir()}/client.key"

        [sinks.dply.buffer]
        type = "disk"
        max_size = {$bufferBytes}
        when_full = "drop_newest"
        TOML;
    }

    /**
     * @param  list<string>  $values
     */
    protected function tomlStringArray(array $values): string
    {
        return '['.implode(', ', array_map(static fn (string $v): string => '"'.$v.'"', $values)).']';
    }

    protected function binaryPath(): string
    {
        return self::BINARY_PATH;
    }

    protected function configDir(): string
    {
        return self::CONFIG_DIR;
    }

    protected function configPath(): string
    {
        return self::CONFIG_PATH;
    }

    protected function dataDir(): string
    {
        return self::DATA_DIR;
    }

    protected function unitName(): string
    {
        return self::UNIT_NAME;
    }

    protected function unitPath(): string
    {
        return self::UNIT_PATH;
    }
}
