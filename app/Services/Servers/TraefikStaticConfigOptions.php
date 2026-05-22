<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;
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
 * Save flow: snapshot → atomic install → `traefik` parse-check (best
 * effort; Traefik has no proper config-test flag) → `systemctl restart
 * traefik`. On a failed parse / failed restart, we restore the
 * snapshot.
 */
class TraefikStaticConfigOptions
{
    private const REMOTE_PATH = '/etc/traefik/traefik.yml';

    /**
     * Form-friendly path → metadata. `path` uses dot notation
     * (`log.level`, `certificatesResolvers.letsencrypt.acme.email`) to
     * locate a value inside the parsed YAML.
     *
     * @var array<string, array{path: string, type: string, default: string, label: string, help: string}>
     */
    public const PARAMS = [
        'api_dashboard' => [
            'path' => 'api.dashboard',
            'type' => 'bool',
            'default' => '1',
            'label' => 'API dashboard',
            'help' => 'Enables the built-in dashboard at /dashboard/. dply uses it for the live-state probe.',
        ],
        'api_insecure' => [
            'path' => 'api.insecure',
            'type' => 'bool',
            'default' => '1',
            'label' => 'API insecure (localhost only)',
            'help' => 'Exposes the API on a plain `traefik` entryPoint (127.0.0.1 only in dply\'s setup). Off requires you to wire up auth.',
        ],
        'log_level' => [
            'path' => 'log.level',
            'type' => 'string',
            'default' => 'INFO',
            'label' => 'Log level',
            'help' => 'One of DEBUG, INFO, WARN, ERROR, FATAL, PANIC.',
        ],
        'log_filePath' => [
            'path' => 'log.filePath',
            'type' => 'string',
            'default' => '',
            'label' => 'Log file path',
            'help' => 'Where Traefik writes its operational log. Blank = stdout (captured by journald).',
        ],
        'accessLog_filePath' => [
            'path' => 'accessLog.filePath',
            'type' => 'string',
            'default' => '',
            'label' => 'Access log path',
            'help' => 'Where Traefik writes per-request access logs. Blank = disabled.',
        ],
        'accessLog_format' => [
            'path' => 'accessLog.format',
            'type' => 'string',
            'default' => '',
            'label' => 'Access log format',
            'help' => 'One of `common` (CLF) or `json`. Blank = Traefik default (`common`).',
        ],
        'acme_email' => [
            'path' => 'certificatesResolvers.letsencrypt.acme.email',
            'type' => 'string',
            'default' => '',
            'label' => 'ACME account email',
            'help' => 'Email registered with Let\'s Encrypt / ZeroSSL. Blank = no automatic cert issuance.',
        ],
        'acme_storage' => [
            'path' => 'certificatesResolvers.letsencrypt.acme.storage',
            'type' => 'string',
            'default' => '/var/lib/traefik/acme.json',
            'label' => 'ACME storage',
            'help' => 'Path Traefik uses to persist issued certs. Default is dply\'s provisioner-managed path.',
        ],
    ];

    /**
     * @return array{values: array<string, string>, exists: bool, unreadable: bool, raw: string}
     */
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
     * @param  array<string, string>  $values
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
                $parsed = $this->setByPath($parsed, $meta['path'], $bool);

                continue;
            }
            if ($raw === '' && $meta['default'] === '') {
                // Optional blank — remove the key entirely so YAML stays clean.
                $parsed = $this->unsetByPath($parsed, $meta['path']);

                continue;
            }
            $parsed = $this->setByPath($parsed, $meta['path'], $raw);
        }

        // Render canonical YAML. Inline level 6 keeps deep maps as block
        // style; 2-space indent matches Traefik's docs.
        $newContents = Yaml::dump($parsed, 6, 2, Yaml::DUMP_NULL_AS_TILDE);
        if ($newContents === $contents) {
            $emit->info('No changes to traefik.yml.');

            return;
        }

        $this->stageInstallRestart($ssh, $emit, $newContents);
        $emit->success('Traefik restarted with the new static config.');
    }

    /**
     * Walk a dot-path inside the parsed array.
     *
     * @param  array<string, mixed>  $tree
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
     * @param  array<string, mixed>  $tree
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
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     */
    private function unsetByPath(array $tree, string $path): array
    {
        $segments = explode('.', $path);
        $this->recursiveUnset($tree, $segments);

        return $tree;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  list<string>  $segments
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

    private function stageInstallRestart(SshConnection $ssh, ConsoleEmitter $emit, string $newContents): void
    {
        $emit->step('traefik-static', 'Staging new traefik.yml to /tmp');
        $tmpRemote = '/tmp/dply-traefik.yml.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $ssh->exec(sprintf('printf %s | base64 -d | sudo -n tee %s > /dev/null', escapeshellarg($encoded), escapeshellarg($tmpRemote)), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new traefik.yml');
            throw new \RuntimeException('Failed to stage the new traefik.yml.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('traefik-static', 'Snapshotting current traefik.yml to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step('traefik-static', 'Installing new traefik.yml at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous traefik.yml left in place');
            throw new \RuntimeException('Failed to install the new traefik.yml.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        // Traefik has no config-test flag. Best we can do is restart and
        // check if the unit comes back active. systemd's `--wait` ensures
        // we don't claim success before the daemon transitions to active.
        $emit->step('traefik-static', 'Restarting Traefik (`systemctl restart traefik`)');
        $emit->info('Static config requires a restart (not reload). Edge briefly drops connections.');
        $restart = $ssh->exec('sudo -n systemctl restart traefik 2>&1; echo "__exit__:$?"', 30);
        $restartExit = (preg_match('/__exit__:(\d+)\s*$/', $restart, $rm) === 1) ? (int) $rm[1] : 1;
        $restartOut = (string) preg_replace('/__exit__:\d+\s*$/', '', $restart);

        // Confirm the unit is active. Restart can return 0 even if the
        // process crashes immediately after.
        $statusOut = $ssh->exec('systemctl is-active traefik 2>&1', 10);
        $isActive = trim($statusOut) === 'active';

        if ($restartExit !== 0 || ! $isActive) {
            $emit->step('traefik-static', 'Traefik did not come back active — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $ssh->exec('sudo -n systemctl restart traefik 2>&1', 30);
            // Capture a snippet of the failure log for the operator.
            $journal = $ssh->exec('sudo -n journalctl --no-pager -u traefik -n 20 --since "-1 min" 2>&1', 15);
            foreach (preg_split('/\R/', trim($journal)) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $emit($line, ConsoleAction::LEVEL_WARN);
                }
            }
            throw new \RuntimeException('Traefik failed to come back active; previous traefik.yml restored.'."\n".trim($restartOut));
        }

        $emit->success('Traefik is active.');
    }
}
