<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Jobs\Concerns\WritesPerSiteWebserverConfigs;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;
use App\Support\Servers\TraefikAdminUrl;
use Symfony\Component\Yaml\Yaml;

/**
 * Read + write Traefik's static config at `/etc/traefik/traefik.yml`.
 * YAML, not the INI/brace formats used by the other engines, so we
 * round-trip via Symfony YAML rather than hand-rolling a parser.
 *
 * Static config requires a Traefik RESTART, not just a reload — the
 * banner copy makes this explicit. Dynamic config (in
 * /etc/traefik/dynamic/*.yml) hot-reloads via the file provider; that's
 * out of scope here.
 *
 * dply's `AddEdgeProxyJob::writeTraefikStaticConfig()` rewrites this
 * file on every edge-proxy add — operators tuning it here see their
 * edits revert if they reinstall the edge proxy. The UI surfaces that.
 *
 * Save flow: snapshot → atomic install → `systemctl restart traefik`.
 * Traefik v3.1 has no `traefik --validate` CLI — restart + unit status
 * is the real check. On a failed restart, we restore the snapshot.
 */
class TraefikStaticConfigOptions
{
    use PrivilegedRemoteFileWrites;
    use WritesPerSiteWebserverConfigs;

    private const REMOTE_PATH = '/etc/traefik/traefik.yml';

    /**
     * Install Traefik binary + systemd unit (shared with {@see AddEdgeProxyJob}).
     */
    public static function installScript(): string
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

if [ -x /usr/local/bin/traefik ] && [ -f /etc/systemd/system/traefik.service ]; then
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
cat > /etc/systemd/system/traefik.service <<'UNIT'
[Unit]
Description=Traefik
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/traefik --configFile=/etc/traefik/traefik.yml
ExecReload=/bin/kill -HUP $MAINPID
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
[ -x /usr/local/bin/traefik ] || { echo "[dply] traefik binary missing at /usr/local/bin/traefik" >&2; exit 127; }
[ -f /etc/systemd/system/traefik.service ] || { echo "[dply] traefik.service file missing" >&2; exit 127; }
systemctl cat traefik.service >/dev/null 2>&1 || { echo "[dply] traefik.service not picked up by systemd" >&2; exit 127; }
BASH;
    }

    /**
     * Form-friendly path → metadata. `path` uses dot notation
     * (`log.level`, `certificatesResolvers.letsencrypt.acme.email`) to
     * locate a value inside the parsed YAML.
     *
     * @var array<string, array{path: string, type: string, default: string, label: string, help: string}>
     */
    public const PARAMS = [
        'entrypoint_web_address' => [
            'path' => 'entryPoints.web.address',
            'type' => 'string',
            'default' => ':80',
            'group' => 'entrypoints',
            'label' => 'Public web entry point',
            'help' => 'Address Traefik listens on for site traffic (usually :80). Changing this affects every HTTP router on the web entry point.',
        ],
        'providers_file_directory' => [
            'path' => 'providers.file.directory',
            'type' => 'string',
            'default' => '/etc/traefik/dynamic',
            'group' => 'providers',
            'label' => 'File provider directory',
            'help' => 'Directory watched for dynamic routing YAML. dply site routes are written here.',
        ],
        'providers_file_watch' => [
            'path' => 'providers.file.watch',
            'type' => 'bool',
            'default' => '1',
            'group' => 'providers',
            'label' => 'File provider watch',
            'help' => 'When enabled, Traefik reloads dynamic files automatically when they change on disk.',
        ],
        'ping' => [
            'path' => 'ping',
            'type' => 'bool',
            'default' => '0',
            'group' => 'health',
            'label' => 'Ping endpoint',
            'help' => 'Enables Traefik\'s /ping health check (see Traefik docs — Ping).',
        ],
        'global_checkNewVersion' => [
            'path' => 'global.checkNewVersion',
            'type' => 'bool',
            'default' => '1',
            'group' => 'global',
            'label' => 'Check for new version',
            'help' => 'When enabled, Traefik may notify about newer releases in logs.',
        ],
        'global_sendAnonymousUsage' => [
            'path' => 'global.sendAnonymousUsage',
            'type' => 'bool',
            'default' => '0',
            'group' => 'global',
            'label' => 'Send anonymous usage',
            'help' => 'Opt-in anonymous usage statistics sent to Traefik Labs.',
        ],
        'api_dashboard' => [
            'path' => 'api.dashboard',
            'type' => 'bool',
            'default' => '1',
            'group' => 'api',
            'label' => 'API dashboard',
            'help' => 'Enables the built-in dashboard at /dashboard/. dply uses it for the live-state probe.',
        ],
        'api_insecure' => [
            'path' => 'api.insecure',
            'type' => 'bool',
            'default' => '1',
            'group' => 'api',
            'label' => 'API insecure (localhost only)',
            'help' => 'Exposes the API on a plain `traefik` entryPoint (127.0.0.1 only in dply\'s setup). Off requires you to wire up auth.',
        ],
        'log_level' => [
            'path' => 'log.level',
            'type' => 'string',
            'default' => 'INFO',
            'group' => 'logging',
            'label' => 'Log level',
            'help' => 'One of DEBUG, INFO, WARN, ERROR, FATAL, PANIC.',
        ],
        'log_filePath' => [
            'path' => 'log.filePath',
            'type' => 'string',
            'default' => '',
            'group' => 'logging',
            'label' => 'Log file path',
            'help' => 'Where Traefik writes its operational log. Blank = stdout (captured by journald).',
        ],
        'accessLog_filePath' => [
            'path' => 'accessLog.filePath',
            'type' => 'string',
            'default' => '',
            'group' => 'logging',
            'label' => 'Access log path',
            'help' => 'Where Traefik writes per-request access logs. Blank = disabled.',
        ],
        'accessLog_format' => [
            'path' => 'accessLog.format',
            'type' => 'string',
            'default' => '',
            'group' => 'logging',
            'label' => 'Access log format',
            'help' => 'One of `common` (CLF) or `json`. Blank = Traefik default (`common`).',
        ],
        'acme_email' => [
            'path' => 'certificatesResolvers.letsencrypt.acme.email',
            'type' => 'string',
            'default' => '',
            'group' => 'acme',
            'label' => 'ACME account email',
            'help' => 'Email registered with Let\'s Encrypt / ZeroSSL. Blank = no automatic cert issuance.',
        ],
        'acme_storage' => [
            'path' => 'certificatesResolvers.letsencrypt.acme.storage',
            'type' => 'string',
            'default' => '/var/lib/traefik/acme.json',
            'group' => 'acme',
            'label' => 'ACME storage',
            'help' => 'Path Traefik uses to persist issued certs. Default is dply\'s provisioner-managed path.',
        ],
        'acme_httpChallenge_entryPoint' => [
            'path' => 'certificatesResolvers.letsencrypt.acme.httpChallenge.entryPoint',
            'type' => 'string',
            'default' => 'web',
            'group' => 'acme',
            'label' => 'ACME HTTP challenge entry point',
            'help' => 'Entry point used for HTTP-01 challenges (usually `web`).',
        ],
        'acme_tlsChallenge' => [
            'path' => 'certificatesResolvers.letsencrypt.acme.tlsChallenge',
            'type' => 'bool',
            'default' => '0',
            'group' => 'acme',
            'label' => 'ACME TLS challenge',
            'help' => 'Enable TLS-ALPN-01 challenge instead of (or alongside) HTTP challenge.',
        ],
        'acme_caserver' => [
            'path' => 'certificatesResolvers.letsencrypt.acme.caServer',
            'type' => 'string',
            'default' => '',
            'group' => 'acme',
            'label' => 'ACME CA server URL',
            'help' => 'Override the ACME directory URL (staging CA, private PKI). Blank = Let\'s Encrypt production.',
        ],
    ];

    /** @var array<string, string> */
    public const PARAM_GROUPS = [
        'entrypoints' => 'Entry points',
        'providers' => 'Providers',
        'api' => 'API & dashboard',
        'logging' => 'Logging',
        'acme' => 'ACME / TLS',
        'health' => 'Health',
        'global' => 'Global',
    ];

    /**
     * @return array{values: array<string, string>, exists: bool, unreadable: bool, raw: string}
     */
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        $values = $this->defaults();

        try {
            $ssh = new SshConnection($server);
            $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null', 15);
            if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                return ['values' => $values, 'exists' => false, 'unreadable' => true, 'raw' => ''];
            }
        } catch (\Throwable) {
            return ['values' => $values, 'exists' => false, 'unreadable' => true, 'raw' => ''];
        }

        try {
            $parsed = Yaml::parse($contents);
        } catch (\Throwable) {
            // YAML's broken on disk — show defaults but flag unreadable so
            // the UI doesn't silently let the operator overwrite.
            return ['values' => $values, 'exists' => false, 'unreadable' => true, 'raw' => $contents];
        }
        if (! is_array($parsed)) {
            return ['values' => $values, 'exists' => false, 'unreadable' => false, 'raw' => $contents];
        }

        foreach (self::PARAMS as $key => $meta) {
            $v = $this->getByPath($parsed, $meta['path']);
            if ($v !== null) {
                $values[$key] = $this->normalizeForForm($meta['type'], $v);
            }
        }

        return ['values' => $values, 'exists' => true, 'unreadable' => false, 'raw' => $contents];
    }

    /**
     * @param  array<string, mixed> $values
     *
     * @throws \RuntimeException
     */
    public function save(Server $server, array $values, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $emit->step('traefik-static', 'Reading current traefik.yml');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read traefik.yml from the server.');
        }

        try {
            $parsed = Yaml::parse($contents);
            if (! is_array($parsed)) {
                $parsed = [];
            }
        } catch (\Throwable $e) {
            $emit->error('Existing traefik.yml is not valid YAML: '.$e->getMessage());
            throw new \RuntimeException('Existing traefik.yml is not valid YAML.');
        }

        $emit->info('Merging form values into the YAML tree.');
        foreach (self::PARAMS as $key => $meta) {
            $raw = trim((string) ($values[$key] ?? $meta['default']));
            if ($meta['type'] === 'bool') {
                $bool = in_array($raw, ['1', 'true', 'on', 'yes'], true);
                if (! $bool) {
                    $parsed = $this->unsetByPath($parsed, $meta['path']);

                    continue;
                }
                // Traefik expects empty maps for ping / tlsChallenge toggles.
                $parsed = $this->setByPath(
                    $parsed,
                    $meta['path'],
                    in_array($meta['path'], ['ping', 'certificatesResolvers.letsencrypt.acme.tlsChallenge'], true) ? [] : true,
                );

                continue;
            }
            if ($raw === '' && $meta['default'] === '') {
                // Optional blank — remove the key entirely so YAML stays clean.
                $parsed = $this->unsetByPath($parsed, $meta['path']);

                continue;
            }
            $parsed = $this->setByPath($parsed, $meta['path'], $raw);
        }

        $parsed = $this->ensureDplyTraefikStaticDefaults($server, $parsed);

        // Render canonical YAML. Inline level 6 keeps deep maps as block
        // style; 2-space indent matches Traefik's docs.
        $newContents = Yaml::dump($parsed, 6, 2, Yaml::DUMP_NULL_AS_TILDE);
        if ($newContents === $contents) {
            $emit->info('No changes to traefik.yml.');

            return;
        }

        $this->installYamlAndRestart($server, $newContents, $emitter);
        $emit->success('Traefik restarted with the new static config.');
    }

    /**
     * Install traefik.yml contents and restart Traefik (shared with providers save).
     *
     * @throws \RuntimeException
     */
    public function installYamlAndRestart(Server $server, string $newContents, ?ConsoleEmitter $emitter = null): void
    {
        $ssh = new SshConnection($server);
        $emit = $emitter ?? new ConsoleEmitter(null);
        $this->ensureTraefikInstalled($server, $ssh, $emit);
        $this->stageInstallRestart($server, $ssh, $emit, $newContents);
    }

    /**
     * Re-apply dply's localhost API entry point + dashboard flags and restart Traefik.
     *
     * @throws \RuntimeException
     */
    public function repairAdminApiDefaults(Server $server, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);
        $this->ensureTraefikInstalled($server, $ssh, $emit);

        $emit->step('traefik-static', 'Reading '.self::REMOTE_PATH);
        $contents = $this->execPrivileged($server, $ssh, 'cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null || true', 15);
        $parsed = [];
        if (trim($contents) !== '') {
            try {
                $parsed = Yaml::parse($contents);
                if (! is_array($parsed)) {
                    $parsed = [];
                }
            } catch (\Throwable) {
                throw new \RuntimeException('Existing traefik.yml is not valid YAML.');
            }
        } else {
            $emit->info('traefik.yml missing or empty — will write dply defaults.');
        }

        $listenPort = $this->resolveWebListenPortFromParsed($parsed);
        $newContents = $this->renderCanonicalStaticYaml($listenPort);
        $emit->info(sprintf(
            'Rewriting traefik.yml to dply defaults (public web on :%d, API on %s). Custom static keys (ACME, extra entry points, etc.) are not preserved.',
            $listenPort,
            TraefikAdminUrl::DEFAULT_ADDRESS,
        ));
        $this->installYamlAndRestart($server, $newContents, $emit);
        $emit->success('Traefik API entry point restored and service started.');
    }

    /**
     * Enable + start Traefik without changing traefik.yml (service stopped/crashed).
     *
     * @throws \RuntimeException
     */
    public function startTraefikService(Server $server, ?ConsoleEmitter $emitter = null): void
    {
        if ($server->edgeProxy() !== 'traefik') {
            throw new \RuntimeException('This server does not have Traefik as its edge proxy.');
        }

        $ssh = new SshConnection($server);
        $emit = $emitter ?? new ConsoleEmitter(null);
        $this->ensureTraefikInstalled($server, $ssh, $emit);

        $hasConfig = trim($this->execPrivileged(
            $server,
            $ssh,
            'test -f '.escapeshellarg(self::REMOTE_PATH).' && echo yes || echo no',
            10,
        )) === 'yes';

        if (! $hasConfig) {
            $emit->info('traefik.yml missing — writing dply defaults before start.');
            $this->stageInstallRestart($server, $ssh, $emit, $this->renderCanonicalStaticYaml(80));

            return;
        }

        $this->bringTraefikServiceOnline($server, $ssh, $emit);
    }

    /**
     * Known-good static config written by {@see AddEdgeProxyJob::writeTraefikStaticConfig()}.
     * Repair uses this instead of round-tripping arbitrary YAML — partial ACME blocks and
     * other half-finished operator edits often prevent Traefik from starting after a dump.
     */
    public function renderCanonicalStaticYaml(int $listenPort): string
    {
        return <<<YAML
# Managed by Dply — do NOT hand-edit.
entryPoints:
  web:
    address: ":{$listenPort}"
  metrics:
    address: "127.0.0.1:9093"
  traefik:
    address: "127.0.0.1:9094"
providers:
  file:
    directory: /etc/traefik/dynamic
    watch: true
api:
  dashboard: true
  insecure: true
metrics:
  prometheus:
    entryPoint: metrics
    addServicesLabels: true
    addEntryPointsLabels: true
    addRoutersLabels: true
YAML;
    }

    /**
     * @param  array<string, mixed> $parsed
     */
    public function resolveWebListenPortFromParsed(array $parsed): int
    {
        $entryPoints = is_array($parsed['entryPoints'] ?? null) ? $parsed['entryPoints'] : [];
        $web = is_array($entryPoints['web'] ?? null) ? $entryPoints['web'] : [];
        $address = trim((string) ($web['address'] ?? ':80'));

        if (preg_match('/:(\d{1,5})$/', $address, $matches) === 1) {
            return max(1, min(65535, (int) $matches[1]));
        }

        if (ctype_digit($address)) {
            return max(1, min(65535, (int) $address));
        }

        return 80;
    }

    /**
     * Keep dply-managed localhost API/metrics entry points when operators save static config.
     *
     * @param  array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function ensureDplyTraefikStaticDefaults(Server $server, array $parsed): array
    {
        if ($server->edgeProxy() !== 'traefik') {
            return $parsed;
        }

        $entryPoints = is_array($parsed['entryPoints'] ?? null) ? $parsed['entryPoints'] : [];
        if (! isset($entryPoints['metrics']) || ! is_array($entryPoints['metrics'])) {
            $entryPoints['metrics'] = ['address' => '127.0.0.1:9093'];
        } elseif (trim((string) ($entryPoints['metrics']['address'] ?? '')) === '') {
            $entryPoints['metrics']['address'] = '127.0.0.1:9093';
        }

        if (! isset($entryPoints['web']) || ! is_array($entryPoints['web'])) {
            $entryPoints['web'] = ['address' => ':80'];
        } elseif (trim((string) ($entryPoints['web']['address'] ?? '')) === '') {
            $entryPoints['web']['address'] = ':80';
        }

        if (! isset($entryPoints['traefik']) || ! is_array($entryPoints['traefik'])) {
            $entryPoints['traefik'] = ['address' => TraefikAdminUrl::DEFAULT_ADDRESS];
        } else {
            $entryPoints['traefik']['address'] = TraefikAdminUrl::DEFAULT_ADDRESS;
        }

        $parsed['entryPoints'] = $entryPoints;

        $parsed['api'] = [
            'dashboard' => true,
            'insecure' => true,
        ];

        $providers = is_array($parsed['providers'] ?? null) ? $parsed['providers'] : [];
        $file = is_array($providers['file'] ?? null) ? $providers['file'] : [];
        if (! isset($file['directory']) || trim((string) $file['directory']) === '') {
            $file['directory'] = '/etc/traefik/dynamic';
        }
        if (! array_key_exists('watch', $file)) {
            $file['watch'] = true;
        }
        $providers['file'] = $file;
        $parsed['providers'] = $providers;

        return $parsed;
    }

    /**
     * Walk a dot-path inside the parsed array.
     *
     * @param  array<string, mixed> $parsed
     * @param  array<string, mixed> $tree
     */
    private function getByPath(array $tree, string $path): mixed
    {
        $node = $tree;
        foreach (explode('.', $path) as $seg) {
            if (! is_array($node) || ! array_key_exists($seg, $node)) {
                return null;
            }
            $node = $node[$seg];
        }

        return $node;
    }

    /**
     * Set a dot-path inside the array, creating intermediate maps as needed.
     *
     * @param  array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function setByPath(array $tree, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $ref = &$tree;
        foreach ($segments as $i => $seg) {
            $isLast = $i === count($segments) - 1;
            if ($isLast) {
                $ref[$seg] = $value;

                continue;
            }
            if (! isset($ref[$seg]) || ! is_array($ref[$seg])) {
                $ref[$seg] = [];
            }
            $ref = &$ref[$seg];
        }
        unset($ref);

        return $tree;
    }

    /**
     * Remove a dot-path inside the tree. Empties out intermediate maps that
     * are left with no children so we don't accumulate `api: {}` shells.
     *
     * @param  array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function unsetByPath(array $tree, string $path): array
    {
        $segments = explode('.', $path);
        $this->recursiveUnset($tree, $segments);

        return $tree;
    }

    /**
     * @param  array<string, mixed> $tree
     * @param  array<string, mixed> $segments
     */
    private function recursiveUnset(array &$tree, array $segments): void
    {
        if (count($segments) === 0) {
            return;
        }
        $head = $segments[0];
        if (! array_key_exists($head, $tree)) {
            return;
        }
        if (count($segments) === 1) {
            unset($tree[$head]);

            return;
        }
        if (is_array($tree[$head])) {
            $rest = array_slice($segments, 1);
            $this->recursiveUnset($tree[$head], $rest);
            if ($tree[$head] === []) {
                unset($tree[$head]);
            }
        }
    }

    private function normalizeForForm(string $type, mixed $value): string
    {
        if ($type === 'bool') {
            if (is_array($value)) {
                return $value === [] ? '1' : '1';
            }
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            $s = strtolower((string) $value);

            return in_array($s, ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * @return array<string, string>
     */
    private function defaults(): array
    {
        $out = [];
        foreach (self::PARAMS as $key => $meta) {
            $out[$key] = (string) $meta['default'];
        }

        return $out;
    }

    private function stageInstallRestart(Server $server, SshConnection $ssh, ConsoleEmitter $emit, string $newContents): void
    {
        $emit->step('traefik-static', 'Staging new traefik.yml to /tmp');
        $tmpRemote = '/tmp/dply-traefik.yml.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $stageCmd = sprintf(
            'printf %s | base64 -d > %s',
            escapeshellarg($encoded),
            escapeshellarg($tmpRemote),
        );
        $this->execPrivileged($server, $ssh, $stageCmd, 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new traefik.yml');
            throw new \RuntimeException('Failed to stage the new traefik.yml.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('traefik-static', 'Snapshotting current traefik.yml to '.$bak);
        $this->execPrivileged(
            $server,
            $ssh,
            sprintf('[ -f %s ] && cp -p %s %s || true', escapeshellarg(self::REMOTE_PATH), escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)),
            10,
        );

        $emit->step('traefik-static', 'Installing new traefik.yml at '.self::REMOTE_PATH);
        $this->execPrivileged(
            $server,
            $ssh,
            sprintf('install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)),
            10,
        );
        if ($ssh->lastExecExitCode() !== 0) {
            $this->execPrivileged($server, $ssh, 'rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous traefik.yml left in place');
            throw new \RuntimeException('Failed to install the new traefik.yml.');
        }
        $this->execPrivileged($server, $ssh, 'rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->info('Static config requires a restart (not reload). Edge briefly drops connections.');

        try {
            $this->bringTraefikServiceOnline($server, $ssh, $emit);
        } catch (\RuntimeException $e) {
            $emit->step('traefik-static', 'Traefik did not come back active — restoring '.$bak);
            $this->execPrivileged(
                $server,
                $ssh,
                sprintf('[ -f %s ] && cp -p %s %s || true', escapeshellarg($bak), escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)),
                10,
            );
            try {
                $this->bringTraefikServiceOnline($server, $ssh, $emit);
            } catch (\RuntimeException) {
                // Best effort — previous config may also be broken.
            }

            throw new \RuntimeException(
                'Traefik failed to come back active; previous traefik.yml restored.'."\n".$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function ensureTraefikInstalled(Server $server, SshConnection $ssh, ConsoleEmitter $emit): void
    {
        $check = trim($this->execPrivileged(
            $server,
            $ssh,
            '[ -x /usr/local/bin/traefik ] && [ -f /etc/systemd/system/traefik.service ] && echo ok || echo missing',
            15,
        ));
        if ($check === 'ok') {
            return;
        }

        $emit->step('traefik-static', 'Installing Traefik binary and systemd unit');
        $out = $this->execPrivileged($server, $ssh, self::installScript().'; echo "__exit__:$?"', 300);
        $exit = $this->remoteExitCode($out);
        $this->emitRemoteLines($emit, (string) preg_replace('/__exit__:\d+\s*$/', '', $out));
        if ($exit !== 0) {
            throw new \RuntimeException('Traefik install failed on the server (exit '.$exit.').');
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function bringTraefikServiceOnline(Server $server, SshConnection $ssh, ConsoleEmitter $emit): void
    {
        $webPort = $this->resolveWebListenPortOnServer($server, $ssh);
        $this->freeWebListenPortForTraefik($server, $ssh, $emit, $webPort);

        $this->execPrivileged($server, $ssh, 'systemctl reset-failed traefik 2>/dev/null || true', 10);

        $emit->step('traefik-static', 'Starting Traefik (`systemctl enable --now traefik`)');
        $start = $this->execPrivileged($server, $ssh, 'systemctl enable --now traefik 2>&1; echo "__exit__:$?"', 30);
        $startExit = $this->remoteExitCode($start);
        $startOut = trim((string) preg_replace('/__exit__:\d+\s*$/', '', $start));

        if ($startExit !== 0 || ! $this->waitForTraefikActive($server, $ssh)) {
            $status = trim($this->execPrivileged($server, $ssh, 'systemctl status traefik --no-pager -l 2>&1 | tail -n 25', 15));
            $journal = trim($this->execPrivileged($server, $ssh, 'journalctl --no-pager -u traefik -n 40 --since "-3 min" 2>&1', 15));
            $smoke = trim($this->execPrivileged(
                $server,
                $ssh,
                'timeout 4 /usr/local/bin/traefik --configFile='.escapeshellarg(self::REMOTE_PATH).' 2>&1 | head -n 25 || true',
                12,
            ));
            $this->emitRemoteLines($emit, $journal);
            $detail = $startOut;
            if ($status !== '') {
                $detail = $detail === '' ? $status : $detail."\n\n".$status;
            }
            if ($journal !== '') {
                $detail = $detail === '' ? $journal : $detail."\n\n".$journal;
            }
            if ($smoke !== '') {
                $detail = $detail === '' ? $smoke : $detail."\n\nStartup output:\n".$smoke;
            }
            if (str_contains($detail, 'address already in use') || str_contains($detail, 'bind: address already in use')) {
                $detail .= "\n\n".sprintf(
                    'Port :%d is still held by another process. Traefik must own that port as the edge proxy. Stop nginx/apache/caddy on :%d on the server, or re-run Add edge proxy cutover.',
                    $webPort,
                    $webPort,
                );
            } else {
                $detail = $detail === '' ? $detail : $detail."\n\n".'Also check YAML under /etc/traefik/dynamic/ — invalid dynamic config prevents Traefik from staying up.';
            }

            throw new \RuntimeException(
                'Traefik did not start or is not active.'
                .($detail !== '' ? "\n".$detail : ''),
            );
        }

        $this->execPrivileged(
            $server,
            $ssh,
            '(systemctl is-active --quiet caddy && systemctl reload caddy) || systemctl start caddy 2>/dev/null || true',
            30,
        );

        $emit->success('Traefik is active.');
    }

    private function resolveWebListenPortOnServer(Server $server, SshConnection $ssh): int
    {
        $contents = trim($this->execPrivileged(
            $server,
            $ssh,
            'cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null || true',
            15,
        ));
        if ($contents === '') {
            return 80;
        }

        try {
            $parsed = Yaml::parse($contents);

            return is_array($parsed) ? $this->resolveWebListenPortFromParsed($parsed) : 80;
        } catch (\Throwable) {
            return 80;
        }
    }

    private function freeWebListenPortForTraefik(Server $server, SshConnection $ssh, ConsoleEmitter $emit, int $port): void
    {
        $listeners = trim($this->execPrivileged(
            $server,
            $ssh,
            sprintf('ss -ltnp "sport = :%d" 2>/dev/null | head -n 5 || true', $port),
            10,
        ));
        if ($listeners === '') {
            return;
        }

        $emit->warn(sprintf('Port :%d is in use — stopping the previous webserver so Traefik can bind.', $port));
        foreach (preg_split('/\R/', $listeners) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit->info($line);
            }
        }

        $units = ['nginx', 'apache2', 'httpd', 'lshttpd', 'haproxy'];
        $meta = is_array($server->meta) ? $server->meta : [];
        $previous = strtolower(trim((string) ($meta['edge_proxy_previous_webserver'] ?? '')));
        if ($previous !== '' && $previous !== 'caddy' && $previous !== 'traefik') {
            $prevUnit = $this->systemdUnitForWebserver($previous);
            if ($prevUnit !== null) {
                array_unshift($units, $prevUnit);
            }
        }

        foreach (array_values(array_unique($units)) as $unit) {
            if ($unit === 'traefik') {
                continue;
            }
            $this->execPrivileged($server, $ssh, 'systemctl stop '.escapeshellarg($unit).' 2>/dev/null || true', 15);
        }

        $stillBusy = trim($this->execPrivileged(
            $server,
            $ssh,
            sprintf('ss -ltn "sport = :%d" 2>/dev/null | tail -n +2 | head -n 1 || true', $port),
            10,
        )) !== '';

        if ($stillBusy) {
            $emit->info('Port still busy — stopping Caddy so Traefik can bind :'.$port.' (Caddy stays on backend ports after Traefik starts).');
            $this->execPrivileged($server, $ssh, 'systemctl stop caddy 2>/dev/null || true', 20);
        }

        $this->waitForPortFree($server, $ssh, $port);
    }

    private function waitForTraefikActive(Server $server, SshConnection $ssh): bool
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if ($attempt > 0) {
                $this->execPrivileged($server, $ssh, 'sleep 1', 5);
            }
            $statusOut = trim($this->execPrivileged($server, $ssh, 'systemctl is-active traefik 2>&1', 10));
            if ($statusOut === 'active') {
                return true;
            }
        }

        return false;
    }

    private function execPrivileged(Server $server, SshConnection $ssh, string $command, int $timeout): string
    {
        return $ssh->exec($this->privilegedCommand($server, $command), $timeout);
    }

    private function remoteExitCode(string $output): int
    {
        return (preg_match('/__exit__:(\d+)\s*$/', $output, $matches) === 1) ? (int) $matches[1] : 1;
    }

    private function emitRemoteLines(ConsoleEmitter $emit, string $blob): void
    {
        foreach (preg_split('/\R/', trim($blob)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit->info($line);
            }
        }
    }
}
