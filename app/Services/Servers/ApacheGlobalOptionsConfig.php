<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Read + write Apache's top-level server config in /etc/apache2/apache2.conf.
 *
 * Apache directives are flat `<Name> <value>` lines (no braces). MPM
 * tunables (StartServers / MaxRequestWorkers / ...) live inside
 * `<IfModule mpm_event_module>` (or `mpm_prefork_module` / `mpm_worker_module`)
 * blocks. We look up MPM settings WITHOUT requiring a specific module —
 * whichever MPM the operator's box uses, the values are picked up from the
 * matching block.
 *
 * Site / module configs under sites-enabled / mods-enabled / conf-enabled
 * pass through untouched — dply rewrites those via the per-site provisioner
 * and `a2enmod`/`a2enconf`.
 *
 * Save pipeline: snapshot → atomic install → `apachectl configtest` →
 * `systemctl reload apache2`. On validation failure the .dply-bak.<ts>
 * snapshot is restored.
 */
class ApacheGlobalOptionsConfig
{
    private const REMOTE_PATH = '/etc/apache2/apache2.conf';

    /**
     * Flat top-level directives (case-insensitive in Apache, but we emit
     * canonical CamelCase).
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const TOP_PARAMS = [
        'ServerTokens' => [
            'type' => 'string',
            'default' => 'OS',
            'label' => 'ServerTokens',
            'help' => 'How much Apache identifies itself in Server: headers. `Prod` is the most private (just "Apache").',
        ],
        'ServerSignature' => [
            'type' => 'string',
            'default' => 'Off',
            'label' => 'ServerSignature',
            'help' => '`On` adds a footer line on error pages. `Off` (recommended) hides it. `EMail` adds a mailto link too.',
        ],
        'Timeout' => [
            'type' => 'int',
            'default' => '300',
            'label' => 'Timeout (s)',
            'help' => 'Seconds Apache waits for receives/sends. 60-300 typical.',
        ],
        'KeepAlive' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'KeepAlive',
            'help' => 'Reuse TCP connections for multiple requests. On almost always.',
        ],
        'MaxKeepAliveRequests' => [
            'type' => 'int',
            'default' => '100',
            'label' => 'MaxKeepAliveRequests',
            'help' => 'Max requests per persistent connection. 100 default; bump to 1000+ for high-traffic.',
        ],
        'KeepAliveTimeout' => [
            'type' => 'int',
            'default' => '5',
            'label' => 'KeepAliveTimeout (s)',
            'help' => 'Seconds Apache waits for the next request on an idle keep-alive connection.',
        ],
        'HostnameLookups' => [
            'type' => 'string',
            'default' => 'Off',
            'label' => 'HostnameLookups',
            'help' => '`Off` (recommended) — DNS lookups are slow. `On` resolves client IPs to hostnames in logs.',
        ],
        'ServerAdmin' => [
            'type' => 'string',
            'default' => '',
            'label' => 'ServerAdmin',
            'help' => 'Email shown on default error pages. Blank uses the dply provisioner\'s default.',
        ],
    ];

    /**
     * MPM (Multi-Processing Module) tunables. We probe inside the
     * `<IfModule mpm_event_module>` / `mpm_prefork_module` / `mpm_worker_module`
     * blocks — whichever has values gets read; writes target whichever
     * block is present (or `mpm_event_module` by default, since Ubuntu/dply
     * uses event MPM).
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const MPM_PARAMS = [
        'StartServers' => [
            'type' => 'int',
            'default' => '2',
            'label' => 'StartServers',
            'help' => 'Number of MPM child processes started at boot. Defaults to 2 for event/worker; higher means faster ramp-up under load.',
        ],
        'MinSpareThreads' => [
            'type' => 'int',
            'default' => '25',
            'label' => 'MinSpareThreads',
            'help' => 'Apache spawns workers to maintain at least this many idle threads. Pairs with MaxSpareThreads.',
        ],
        'MaxSpareThreads' => [
            'type' => 'int',
            'default' => '75',
            'label' => 'MaxSpareThreads',
            'help' => 'Cap on idle threads. Above this, Apache reaps workers.',
        ],
        'ThreadLimit' => [
            'type' => 'int',
            'default' => '64',
            'label' => 'ThreadLimit',
            'help' => 'Compile-time max for ThreadsPerChild. Changing requires a full restart, not a reload.',
        ],
        'ThreadsPerChild' => [
            'type' => 'int',
            'default' => '25',
            'label' => 'ThreadsPerChild',
            'help' => 'Threads per child process. event/worker MPMs only.',
        ],
        'MaxRequestWorkers' => [
            'type' => 'int',
            'default' => '150',
            'label' => 'MaxRequestWorkers',
            'help' => 'Hard cap on simultaneous request workers across all children. The single biggest knob for capacity.',
        ],
        'MaxConnectionsPerChild' => [
            'type' => 'int',
            'default' => '0',
            'label' => 'MaxConnectionsPerChild',
            'help' => 'Child process exits after N connections (prevents leaks). 0 = never recycle.',
        ],
    ];

    /**
     * @return array{values: array<string, string>, exists: bool, unreadable: bool, raw: string, mpm: string}
     */
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        $values = $this->defaults();
        $mpm = 'mpm_event_module';

        try {
            $ssh = new SshConnection($server);
            $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null', 15);
            if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                return ['values' => $values, 'exists' => false, 'unreadable' => true, 'raw' => '', 'mpm' => $mpm];
            }
        } catch (\Throwable) {
            return ['values' => $values, 'exists' => false, 'unreadable' => true, 'raw' => '', 'mpm' => $mpm];
        }

        foreach (self::TOP_PARAMS as $key => $meta) {
            $v = $this->extractTopScalar($contents, $key);
            if ($v !== null) {
                $values[$key] = $v;
            }
        }

        // Probe each MPM block in order — the first one with at least one
        // value wins, since Apache only loads one MPM at a time.
        foreach (['mpm_event_module', 'mpm_worker_module', 'mpm_prefork_module'] as $candidate) {
            $body = $this->extractIfModuleBody($contents, $candidate);
            if ($body === null) {
                continue;
            }
            $foundAny = false;
            foreach (self::MPM_PARAMS as $key => $meta) {
                $v = $this->extractTopScalar($body, $key);
                if ($v !== null) {
                    $values['mpm_'.$key] = $v;
                    $foundAny = true;
                }
            }
            if ($foundAny) {
                $mpm = $candidate;
                break;
            }
        }

        return ['values' => $values, 'exists' => true, 'unreadable' => false, 'raw' => $contents, 'mpm' => $mpm];
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

        $emit->step('apache-globals', 'Reading current apache2.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read apache2.conf from the server.');
        }

        $newContents = $contents;

        // Top-level scalars.
        foreach (self::TOP_PARAMS as $key => $meta) {
            $val = trim((string) ($values[$key] ?? $meta['default']));
            // For optional strings, blank means "don't emit".
            if ($val === '' && $meta['type'] === 'string' && $meta['default'] === '') {
                continue;
            }
            $writeVal = $this->normaliseValueForWrite($meta['type'], $val);
            $newContents = $this->upsertTopScalar($newContents, $key, $writeVal);
        }

        // MPM block — find the active block (the one with any current values),
        // or fall back to mpm_event_module (Ubuntu/dply default).
        $targetMpm = 'mpm_event_module';
        foreach (['mpm_event_module', 'mpm_worker_module', 'mpm_prefork_module'] as $candidate) {
            $body = $this->extractIfModuleBody($newContents, $candidate);
            if ($body !== null && trim($body) !== '') {
                $targetMpm = $candidate;
                break;
            }
        }

        $newContents = $this->upsertMpmDirectives($newContents, $targetMpm, $values);

        if ($newContents === $contents) {
            $emit->info('No changes to apache2.conf.');

            return;
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents);
        $emit->success('Apache reloaded with the new global options.');
    }

    private function upsertTopScalar(string $contents, string $key, string $value): string
    {
        // Match `<key> <value>` at depth 0 — i.e. not inside any
        // <IfModule>/<Directory> wrapper. Match the directive
        // case-insensitively, but emit canonical capitalisation.
        $pattern = '/^([\t ]*)'.preg_quote($key, '/').'\s+\S.*$/mi';
        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace($pattern, '$1'.$key.' '.$value, $contents, 1);
        }

        // Insert near the top — after the last leading-section comment / blank.
        return rtrim($contents, "\n")."\n".$key.' '.$value."\n";
    }

    /**
     * @param  array<string, mixed> $values
     */
    private function upsertMpmDirectives(string $contents, string $blockName, array $values): string
    {
        $blockMatch = $this->findIfModuleBlock($contents, $blockName);
        if ($blockMatch === null) {
            // Block doesn't exist in apache2.conf (Ubuntu puts MPM in
            // mods-available/mpm_event.conf). Don't fabricate one here —
            // the operator should edit the mods file directly via the
            // Config editor sub-tab. We still emit a hint via the empty
            // return so the save path runs.
            return $contents;
        }

        [$bodyStart, $bodyEnd] = $blockMatch;
        $body = substr($contents, $bodyStart, $bodyEnd - $bodyStart);
        $newBody = $body;
        foreach (self::MPM_PARAMS as $key => $meta) {
            $formKey = 'mpm_'.$key;
            if (! array_key_exists($formKey, $values)) {
                continue;
            }
            $raw = trim((string) $values[$formKey]);
            if ($raw === '') {
                continue;
            }
            $writeVal = $this->normaliseValueForWrite($meta['type'], $raw);
            $pat = '/^([\t ]*)'.preg_quote($key, '/').'\s+\S.*$/mi';
            if (preg_match($pat, $newBody) === 1) {
                $newBody = (string) preg_replace($pat, '$1'.$key.' '.$writeVal, $newBody, 1);
            } else {
                $newBody = rtrim($newBody, "\n")."\n\t".$key.' '.$writeVal."\n";
            }
        }

        return substr($contents, 0, $bodyStart).$newBody.substr($contents, $bodyEnd);
    }

    private function normaliseValueForWrite(string $type, string $raw): string
    {
        $raw = trim($raw);
        if ($type === 'bool') {
            return in_array($raw, ['1', 'true', 'on', 'On', 'yes'], true) ? 'On' : 'Off';
        }
        if ($type === 'int') {
            return preg_match('/^-?\d+$/', $raw) === 1 ? $raw : '0';
        }

        return $raw;
    }

    /**
     * Pull a top-level directive value at depth 0 (not inside any
     * `<IfModule>` / `<Directory>` / `<VirtualHost>` wrapper). For matches
     * inside a known block, callers pre-slice the block body and feed that
     * to this method.
     */
    private function extractTopScalar(string $contents, string $key): ?string
    {
        $depth = 0;
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') {
                continue;
            }
            // Adjust depth for open/close tags BEFORE matching this line, so
            // a directive on the same line as a `<IfModule ...>` opener is
            // treated as inside.
            $opens = preg_match_all('/<[A-Za-z][^\/<>]*>/', $line) ?: 0;
            $closes = preg_match_all('/<\/[A-Za-z][^<>]*>/', $line) ?: 0;
            // Self-closing-style "<IfModule x>...</IfModule>" on one line nets to 0.
            $delta = $opens - $closes;

            if ($depth === 0 && preg_match('/^'.preg_quote($key, '/').'\s+(\S.*)$/i', $trim, $m) === 1) {
                $val = $m[1];
                // Trim trailing comment.
                $hash = strpos($val, '#');
                if ($hash !== false) {
                    $val = substr($val, 0, $hash);
                }

                return trim($val);
            }

            $depth += $delta;
            if ($depth < 0) {
                $depth = 0;
            }
        }

        return null;
    }

    private function extractIfModuleBody(string $contents, string $module): ?string
    {
        $match = $this->findIfModuleBlock($contents, $module);
        if ($match === null) {
            return null;
        }
        [$start, $end] = $match;

        return substr($contents, $start, $end - $start);
    }

    /**
     * @return array{0: int, 1: int}|null [body start offset, body end offset]
     */
    private function findIfModuleBlock(string $contents, string $module): ?array
    {
        $pattern = '/<IfModule\s+'.preg_quote($module, '/').'\s*>/i';
        if (preg_match($pattern, $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $bodyStart = $m[0][1] + strlen($m[0][0]);
        $end = stripos($contents, '</IfModule>', $bodyStart);
        if ($end === false) {
            return null;
        }

        return [$bodyStart, $end];
    }

    /**
     * @return array<string, string>
     */
    private function defaults(): array
    {
        $out = [];
        foreach (self::TOP_PARAMS as $key => $meta) {
            $out[$key] = (string) $meta['default'];
        }
        foreach (self::MPM_PARAMS as $key => $meta) {
            $out['mpm_'.$key] = (string) $meta['default'];
        }

        return $out;
    }

    private function stageInstallValidateReload(SshConnection $ssh, ConsoleEmitter $emit, string $newContents): void
    {
        $emit->step('apache-globals', 'Staging new apache2.conf to /tmp');
        $tmpRemote = '/tmp/dply-apache2.conf.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $ssh->exec(sprintf('printf %s | base64 -d | sudo -n tee %s > /dev/null', escapeshellarg($encoded), escapeshellarg($tmpRemote)), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new apache2.conf');
            throw new \RuntimeException('Failed to stage the new apache2.conf.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('apache-globals', 'Snapshotting current apache2.conf to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step('apache-globals', 'Installing new apache2.conf at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous apache2.conf left in place');
            throw new \RuntimeException('Failed to install the new apache2.conf.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step('apache-globals', 'Validating with `apachectl configtest`');
        $validate = $ssh->exec('sudo -n apachectl configtest 2>&1; echo "__exit__:$?"', 30);
        $exit = (preg_match('/__exit__:(\d+)\s*$/', $validate, $vm) === 1) ? (int) $vm[1] : 1;
        $stripped = (string) preg_replace('/__exit__:\d+\s*$/', '', $validate);
        foreach (preg_split('/\R/', trim($stripped)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit($line, $exit !== 0 ? ConsoleAction::LEVEL_WARN : ConsoleAction::LEVEL_INFO);
            }
        }
        // apachectl configtest exits 0 even when output has "Syntax OK" so
        // we treat non-zero as failure but also catch error-message patterns
        // in the stdout for the rare case where exit code is misleading.
        $isFailure = $exit !== 0 || (stripos($stripped, 'syntax error') !== false && stripos($stripped, 'syntax ok') === false);
        if ($isFailure) {
            $emit->step('apache-globals', 'Validation failed — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $emit->error('Config validation failed; previous apache2.conf restored.');
            throw new \RuntimeException('Config validation failed; previous apache2.conf restored. apachectl configtest output:'."\n".trim($stripped));
        }
        $emit->success('apache2.conf validated.');

        $emit->step('apache-globals', 'Reloading Apache (`systemctl reload apache2`)');
        $reload = $ssh->exec('sudo -n systemctl reload apache2 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart apache2 2>&1', 30);
        }
    }
}
