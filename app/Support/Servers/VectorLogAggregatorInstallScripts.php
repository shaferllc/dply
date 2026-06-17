<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\ServerLogAggregator;
use App\Support\Servers\Concerns\InstallsVectorBinary;

/**
 * Builds the bash that installs/uninstalls the dply Logs Vector AGGREGATOR on the
 * designated log box, plus the rendered aggregator vector.toml. Sibling to
 * {@see VectorLogAgentInstallScripts} (the edge side); pure string builders, no SSH
 * — {@see \App\Jobs\InstallLogAggregatorJob} runs the output.
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

    public const BINARY_PATH = '/usr/local/bin/dply-vector';

    public const CONFIG_DIR = '/etc/dply-aggregator';

    public const TLS_DIR = '/etc/dply-aggregator/tls';

    public const CONFIG_PATH = '/etc/dply-aggregator/vector.toml';

    public const ENV_PATH = '/etc/dply-aggregator/clickhouse.env';

    /** Small on-box handoff file the job reads (then deletes) to capture edge certs. */
    public const MATERIAL_PATH = '/etc/dply-aggregator/.edge-material';

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

        # --- config --------------------------------------------------------------
        echo "{$configB64}" | base64 -d > "{$this->configPath()}"
        # Bake the listen address + ClickHouse target that are known only here.
        sed -i "s|__LISTEN_PORT__|{$port}|g; s|__CH_HOST__|{$chHost}|g; s|__CH_PORT__|{$chPort}|g; s|__CH_DB__|{$chDb}|g; s|__CH_USER__|{$chUser}|g" "{$this->configPath()}"
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
        return <<<'TOML'
        # Rendered by dply — do not edit by hand. Managed by the dply Logs aggregator installer.
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

        # Map the edge-stamped fields onto the ClickHouse columns; server-side tenant
        # identity comes from the edge-sent dply_org_id/dply_server_id. Replace the
        # event with exactly the sink's columns so nothing unexpected is inserted.
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
        src = string(.source) ?? ""
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
        '''

        [sinks.clickhouse]
        type = "clickhouse"
        inputs = ["normalize"]
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
