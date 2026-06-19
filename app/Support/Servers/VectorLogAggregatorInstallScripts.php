<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\ServerLogAggregator;
use App\Support\Servers\Concerns\InstallsVectorBinary;

/**
 * Builds the bash that installs/uninstalls the dply Logs Vector AGGREGATOR on the
 * designated log box, plus the rendered aggregator vector.toml. Sibling to
 * {@see VectorLogAgentInstallScripts} (the edge side); pure string builders, no SSH
 * — {@see \App\Modules\Logs\Jobs\InstallLogAggregatorJob} runs the output.
 *
 * Pipeline it stands up: `vector` source on the listen port (mTLS, verify the edge
 * client cert) → remap that maps the edge-stamped fields onto the ClickHouse columns
 * → clickhouse sink into dply_logs.server_logs. The mTLS PKI is generated ON the box
 * with openssl (the private keys never leave it except the edge client material,
 * which the job captures back so edges can be configured). The ClickHouse password
 * is read from the box's own credential file at install time and injected via a
 * systemd EnvironmentFile — never baked into the rendered config or the DB.
 *
 * See docs/SERVER_LOGS_ADDON.md.
 */
class VectorLogAggregatorInstallScripts
{
    use InstallsVectorBinary;

    /**
     * Version of the rendered aggregator CONFIG (the vector.toml pipeline) — NOT the
     * Vector binary version ({@see parseVersion}). BUMP THIS whenever renderVectorToml()
     * changes in a way that needs a re-sync to take effect on the box. The installer
     * stamps it into the config header + persists it on the {@see ServerLogAggregator}
     * row, so the platform can detect a box running a stale config and prompt to re-sync.
     *
     * History:
     *   1 — initial aggregator pipeline (edges → normalize → clickhouse).
     *   2 — copy/derive the `source` column; per-org policy + hard-cap quota gate.
     */
    public const CONFIG_VERSION = 2;

    public const BINARY_PATH = '/usr/local/bin/dply-vector';

    public const CONFIG_DIR = '/etc/dply-aggregator';

    public const TLS_DIR = '/etc/dply-aggregator/tls';

    public const CONFIG_PATH = '/etc/dply-aggregator/vector.toml';

    public const ENV_PATH = '/etc/dply-aggregator/clickhouse.env';

    /** Small on-box handoff file the job reads (then deletes) to capture edge certs. */
    public const MATERIAL_PATH = '/etc/dply-aggregator/.edge-material';

    /** Per-org policy CSV (Vector enrichment table): org_id → retention_days, allowed. */
    public const POLICY_PATH = '/etc/dply-aggregator/policy.csv';

    public const DATA_DIR = '/var/lib/dply-aggregator';

    public const UNIT_NAME = 'dply-aggregator.service';

    public const UNIT_PATH = '/etc/systemd/system/dply-aggregator.service';

    /** Where ClickHouse listens on the box (HTTP). The aggregator is co-located. */
    public const CLICKHOUSE_PASSWORD_FILE = '/root/.dply-clickhouse-password';

    public function installScript(ServerLogAggregator $aggregator): string
    {
        $port = $aggregator->listen_port > 0 ? $aggregator->listen_port : 6000;
        $configB64 = base64_encode($this->renderVectorToml());
        $unitB64 = base64_encode($this->renderSystemdUnit());

        $chHost = '127.0.0.1';
        $chPort = (int) config('server_logs.clickhouse.http_port', 8123);
        $chDb = (string) config('server_logs.clickhouse.database', 'dply_logs');
        $chUser = (string) config('server_logs.clickhouse.username', 'default');
        $defaultRetention = max(1, (int) config('server_logs.clickhouse.retention_days', 7));
        $policyHeader = \App\Modules\Logs\Services\ServerLogAggregatorPolicyMap::HEADER;

        return <<<BASH
        # --- fetch + verify Vector ----------------------------------------------
        {$this->vectorBinaryInstallFragment(self::BINARY_PATH)}

        # --- directories ---------------------------------------------------------
        install -d -m 0750 "{$this->configDir()}"
        install -d -m 0700 "{$this->tlsDir()}"
        install -d -m 0750 "{$this->dataDir()}"

        # --- mTLS PKI (generated on-box; private keys never leave) ---------------
        {$this->pkiFragment($aggregator)}

        # --- ClickHouse password (read on-box, injected via EnvironmentFile) -----
        CH_PW="\$(cat "{$this->clickHousePasswordFile()}" 2>/dev/null || true)"
        umask 077
        printf 'CH_PASSWORD=%s\n' "\$CH_PW" > "{$this->envPath()}"
        chmod 0600 "{$this->envPath()}"

        # --- per-org policy enrichment table (seed header so `validate` passes;
        #     the real rows are shipped + refreshed by SyncLogAggregatorPolicyJob) -
        if [ ! -s "{$this->policyPath()}" ]; then
          printf '%s\n' "{$policyHeader}" > "{$this->policyPath()}"
        fi
        chmod 0644 "{$this->policyPath()}"

        # --- config --------------------------------------------------------------
        echo "{$configB64}" | base64 -d > "{$this->configPath()}"
        # Bake the listen address + ClickHouse target that are known only here.
        sed -i "s|__LISTEN_PORT__|{$port}|g; s|__CH_HOST__|{$chHost}|g; s|__CH_PORT__|{$chPort}|g; s|__CH_DB__|{$chDb}|g; s|__CH_USER__|{$chUser}|g; s|__DEFAULT_RETENTION__|{$defaultRetention}|g" "{$this->configPath()}"
        chmod 0640 "{$this->configPath()}"

        CH_PASSWORD="\$CH_PW" "{$this->binaryPath()}" validate "{$this->configPath()}" || { echo "aggregator config validation failed"; exit 1; }

        # --- systemd unit --------------------------------------------------------
        echo "{$unitB64}" | base64 -d > "{$this->unitPath()}"
        chmod 0644 "{$this->unitPath()}"
        systemctl daemon-reload
        systemctl enable --now {$this->unitName()}
        systemctl restart {$this->unitName()}

        # --- firewall ------------------------------------------------------------
        if command -v ufw >/dev/null 2>&1; then
          ufw allow {$port}/tcp >/dev/null 2>&1 || true
        fi

        # --- edge material handoff (job reads + deletes this; NOT streamed) ------
        {
          printf 'DPLY_EDGE_CA_B64=%s\n' "\$(base64 -w0 "{$this->tlsDir()}/ca.crt")"
          printf 'DPLY_EDGE_CRT_B64=%s\n' "\$(base64 -w0 "{$this->tlsDir()}/client.crt")"
          printf 'DPLY_EDGE_KEY_B64=%s\n' "\$(base64 -w0 "{$this->tlsDir()}/client.key")"
        } > "{$this->materialPath()}"
        chmod 0600 "{$this->materialPath()}"

        # --- report --------------------------------------------------------------
        sleep 1
        systemctl is-active --quiet {$this->unitName()} || { echo "dply-aggregator failed to start"; systemctl status {$this->unitName()} --no-pager -l | tail -n 40; exit 1; }
        echo "dply-aggregator installed and running on :{$port}"
        {$this->binaryPath()} --version
        BASH;
    }

    /**
     * Bash the job runs SEPARATELY (output captured privately, never persisted into
     * install_output) to read the generated edge mTLS material, then shred the
     * handoff file from the box.
     */
    public function fetchEdgeMaterialScript(): string
    {
        return <<<BASH
        cat "{$this->materialPath()}"
        rm -f "{$this->materialPath()}"
        BASH;
    }

    public function uninstallScript(): string
    {
        $port = (int) config('server_logs.aggregator_listen_port', 6000);

        return <<<BASH
        systemctl disable --now {$this->unitName()} 2>/dev/null || true
        rm -f "{$this->unitPath()}"
        systemctl daemon-reload 2>/dev/null || true
        if command -v ufw >/dev/null 2>&1; then
          ufw delete allow {$port}/tcp >/dev/null 2>&1 || true
        fi
        rm -rf "{$this->configDir()}" "{$this->dataDir()}"
        echo "dply-aggregator uninstalled"
        BASH;
    }

    /**
     * openssl PKI: a private CA, a server cert (SAN = the box's reachable addresses
     * so edges can verify it) and an edge client cert. Idempotent — only generated
     * when the CA is absent, so re-running install never rotates certs out from
     * under already-configured edges.
     */
    protected function pkiFragment(ServerLogAggregator $aggregator): string
    {
        $tls = $this->tlsDir();
        $san = $this->serverSan($aggregator);

        return <<<BASH
        if [ ! -f "{$tls}/ca.crt" ]; then
          openssl genrsa -out "{$tls}/ca.key" 4096 2>/dev/null
          openssl req -x509 -new -nodes -key "{$tls}/ca.key" -sha256 -days 3650 -subj "/CN=dply-logs-ca" -out "{$tls}/ca.crt" 2>/dev/null
          openssl genrsa -out "{$tls}/server.key" 4096 2>/dev/null
          openssl req -new -key "{$tls}/server.key" -subj "/CN=dply-aggregator" -out "{$tls}/server.csr" 2>/dev/null
          printf 'subjectAltName=%s\n' "{$san}" > "{$tls}/server.ext"
          openssl x509 -req -in "{$tls}/server.csr" -CA "{$tls}/ca.crt" -CAkey "{$tls}/ca.key" -CAcreateserial -days 3650 -sha256 -extfile "{$tls}/server.ext" -out "{$tls}/server.crt" 2>/dev/null
          openssl genrsa -out "{$tls}/client.key" 4096 2>/dev/null
          openssl req -new -key "{$tls}/client.key" -subj "/CN=dply-edge" -out "{$tls}/client.csr" 2>/dev/null
          openssl x509 -req -in "{$tls}/client.csr" -CA "{$tls}/ca.crt" -CAkey "{$tls}/ca.key" -CAcreateserial -days 3650 -sha256 -out "{$tls}/client.crt" 2>/dev/null
          chmod 0600 "{$tls}"/*.key
          rm -f "{$tls}"/*.csr "{$tls}"/*.ext "{$tls}"/*.srl
        fi
        test -s "{$tls}/server.crt" || { echo "aggregator PKI generation failed"; exit 1; }
        BASH;
    }

    /**
     * SAN list for the aggregator server cert: every address an edge might dial it
     * by — public IP, private IP, loopback, localhost.
     */
    protected function serverSan(ServerLogAggregator $aggregator): string
    {
        $parts = ['IP:127.0.0.1', 'DNS:localhost'];
        $public = trim((string) ($aggregator->server->ip_address ?? ''));
        $private = trim((string) ($aggregator->server->private_ip_address ?? ''));
        if ($public !== '') {
            $parts[] = 'IP:'.$public;
        }
        if ($private !== '' && $private !== $public) {
            $parts[] = 'IP:'.$private;
        }

        return implode(',', $parts);
    }

    /**
     * Aggregator vector.toml. Placeholders (__LISTEN_PORT__ etc.) are filled on-box
     * by the install script so box-specific values stay out of the rendered string;
     * the ClickHouse password comes from the ${CH_PASSWORD} env (EnvironmentFile).
     */
    public function renderVectorToml(): string
    {
        $toml = <<<'TOML'
        # Rendered by dply — do not edit by hand. Managed by the dply Logs aggregator installer.
        # dply-config-version: __CONFIG_VERSION__
        data_dir = "/var/lib/dply-aggregator"

        [sources.edges]
        type = "vector"
        address = "0.0.0.0:__LISTEN_PORT__"

        [sources.edges.tls]
        enabled = true
        ca_file = "/etc/dply-aggregator/tls/ca.crt"
        crt_file = "/etc/dply-aggregator/tls/server.crt"
        key_file = "/etc/dply-aggregator/tls/server.key"
        verify_certificate = true

        # Per-org policy table (org_id → retention_days, allowed), refreshed on the
        # box by SyncLogAggregatorPolicyJob. Orgs absent here take the defaults below
        # (fail open): __DEFAULT_RETENTION__ days, allowed. CSV is seeded header-only
        # at install so `vector validate` succeeds before any rows are shipped.
        [enrichment_tables.policy]
        type = "file"

        [enrichment_tables.policy.file]
        path = "/etc/dply-aggregator/policy.csv"
        encoding = { type = "csv" }

        [enrichment_tables.policy.schema]
        org_id = "string"
        retention_days = "integer"
        allowed = "boolean"

        # Map the edge-stamped fields onto the ClickHouse columns; server-side tenant
        # identity comes from the edge-sent dply_org_id/dply_server_id. Replace the
        # event with exactly the sink's columns so nothing unexpected is inserted,
        # then stamp per-org retention_days + an allow flag from the policy table.
        [transforms.normalize]
        type = "remap"
        inputs = ["edges"]
        source = '''
        org = string(.dply_org_id) ?? ""
        srv = string(.dply_server_id) ?? ""
        site = string(.dply_site_id) ?? ""
        unit = string(._SYSTEMD_UNIT) ?? string(.unit) ?? ""
        host = string(.host) ?? string(.hostname) ?? ""
        lvl = string(.level) ?? string(.PRIORITY) ?? ""
        msg = string(.message) ?? ""
        # Source: prefer the edge-stamped .source, but DERIVE it here when absent so
        # the column populates even for edges installed before source tagging — one
        # aggregator re-sync fixes every server, no per-edge re-sync needed. File
        # sources carry .file (the path); journald carries _SYSTEMD_UNIT/PRIORITY.
        src = string(.source) ?? ""
        if src == "" {
            fpath = string(.file) ?? ""
            if fpath != "" {
                if contains(fpath, "nginx") || contains(fpath, "caddy") {
                    src = "web"
                } else if contains(fpath, "php") {
                    src = "php_fpm"
                } else if contains(fpath, "auth.log") {
                    src = "auth"
                } else if contains(fpath, "/storage/logs/") {
                    src = "site_app"
                } else {
                    src = "file"
                }
            } else if exists(._SYSTEMD_UNIT) || exists(.PRIORITY) || exists(.__REALTIME_TIMESTAMP) {
                src = "journald"
            }
        }
        ts = .timestamp
        . = {}
        .org_id = org
        .server_id = srv
        .site_id = site
        .source = src
        .unit = unit
        .level = lvl
        .host = host
        .message = msg
        if ts != null { .timestamp = ts }
        .retention_days = __DEFAULT_RETENTION__
        .dply_allowed = true
        policy, perr = get_enrichment_table_record("policy", {"org_id": org})
        if perr == null {
            rd = to_int(policy.retention_days) ?? __DEFAULT_RETENTION__
            if rd > 0 { .retention_days = rd }
            .dply_allowed = to_bool(policy.allowed) ?? true
        }
        '''

        # Hard-cap quota gate (PR C2): drop events for orgs the policy marks
        # allowed=false (over their hard cap) BEFORE the ClickHouse insert — no
        # store cost, no bill. Default-allow, so an absent/blank policy never drops.
        [transforms.quota]
        type = "filter"
        inputs = ["normalize"]
        condition = '.dply_allowed != false'

        [sinks.clickhouse]
        type = "clickhouse"
        inputs = ["quota"]
        endpoint = "http://__CH_HOST__:__CH_PORT__"
        database = "__CH_DB__"
        table = "server_logs"
        skip_unknown_fields = true
        date_time_best_effort = true

        [sinks.clickhouse.auth]
        strategy = "basic"
        user = "__CH_USER__"
        password = "${CH_PASSWORD}"

        [sinks.clickhouse.batch]
        max_events = 10000
        timeout_secs = 5

        [sinks.clickhouse.buffer]
        type = "disk"
        max_size = 1073741824
        when_full = "block"
        TOML;

        return str_replace('__CONFIG_VERSION__', (string) self::CONFIG_VERSION, $toml);
    }

    /**
     * The config version this code renders — what a freshly re-synced box runs.
     */
    public function configVersion(): int
    {
        return self::CONFIG_VERSION;
    }

    public function renderSystemdUnit(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents($this->unitTemplatePath()));
    }

    protected function unitTemplatePath(): string
    {
        return resource_path('server-scripts/dply-aggregator.service');
    }

    public function parseVersion(string $buffer): ?string
    {
        if (preg_match('/vector\s+(\d+\.\d+\.\d+)/i', $buffer, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    protected function binaryPath(): string
    {
        return self::BINARY_PATH;
    }

    protected function configDir(): string
    {
        return self::CONFIG_DIR;
    }

    protected function tlsDir(): string
    {
        return self::TLS_DIR;
    }

    protected function configPath(): string
    {
        return self::CONFIG_PATH;
    }

    protected function envPath(): string
    {
        return self::ENV_PATH;
    }

    protected function materialPath(): string
    {
        return self::MATERIAL_PATH;
    }

    public function policyPath(): string
    {
        return self::POLICY_PATH;
    }

    /**
     * Bash that writes the shipped policy CSV to the box and reloads the
     * aggregator so Vector reloads the enrichment table. Run by
     * {@see \App\Modules\Logs\Jobs\SyncLogAggregatorPolicyJob}; $csvB64 is the rendered CSV.
     */
    public function syncPolicyScript(string $csvB64): string
    {
        $policy = $this->policyPath();

        return <<<BASH
        install -d -m 0750 "{$this->configDir()}"
        echo "{$csvB64}" | base64 -d > "{$policy}.new"
        # Only swap + reload when the policy actually changed — Vector rereads the
        # enrichment table on restart, and a no-op restart every hour is needless
        # churn (and a brief blip for connected edges).
        if cmp -s "{$policy}.new" "{$policy}"; then
          rm -f "{$policy}.new"
          echo "policy unchanged"
          exit 0
        fi
        mv "{$policy}.new" "{$policy}"
        chmod 0644 "{$policy}"
        systemctl restart {$this->unitName()}
        systemctl is-active --quiet {$this->unitName()} || { echo "dply-aggregator failed to restart after policy sync"; exit 1; }
        echo "policy synced + reloaded ($(wc -l < "{$policy}") line(s))"
        BASH;
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

    protected function clickHousePasswordFile(): string
    {
        return self::CLICKHOUSE_PASSWORD_FILE;
    }
}
