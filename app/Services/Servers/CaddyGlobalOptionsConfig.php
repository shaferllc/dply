<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Read + write Caddy's global-options `{ ... }` block at the top of
 * `/etc/caddy/Caddyfile`. Operates on the global block only — site blocks
 * and snippets pass through byte-for-byte.
 *
 * The save pipeline mirrors the OLS cache-module service:
 *   snapshot → atomic install → `caddy validate` → graceful reload
 * with auto-restore from the .dply-bak.<ts> snapshot on validation failure.
 *
 * Caddy's global block is whitespace-sensitive but not as picky as OLS;
 * we keep nested blocks (`servers { ... }`, `log default { ... }`) on
 * their own lines with 4-space indent and emit one directive per line so
 * `caddy fmt` is a no-op afterwards.
 */
class CaddyGlobalOptionsConfig
{
    private const REMOTE_PATH = '/etc/caddy/Caddyfile';

    /**
     * Top-level scalar directives. Inner blocks (servers / log) are
     * handled separately because they need extra structure.
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const TOP_PARAMS = [
        'email' => [
            'type' => 'string',
            'default' => '',
            'label' => 'ACME account email',
            'help' => 'Email Caddy uses when registering with Let\'s Encrypt / ZeroSSL. Blank disables registration.',
        ],
        'admin' => [
            'type' => 'string',
            'default' => 'localhost:2019',
            'label' => 'Admin endpoint',
            'help' => 'Where Caddy exposes its admin API. `off` disables it (also disables dply\'s Caddy live-state probe). `localhost:2019` is the default.',
        ],
        'default_sni' => [
            'type' => 'string',
            'default' => '',
            'label' => 'Default SNI',
            'help' => 'Server name Caddy presents to clients connecting with no SNI (rare). Blank = leave default.',
        ],
        'auto_https' => [
            'type' => 'string',
            'default' => 'on',
            'label' => 'Auto HTTPS mode',
            'help' => 'One of `on` (default), `off`, `disable_redirects`, `ignore_loaded_certs`, `disable_certs`.',
        ],
        'local_certs' => [
            'type' => 'bool',
            'default' => '0',
            'label' => 'Use local CA for all certs',
            'help' => 'When on, Caddy issues internal certs for every site (no public ACME). Useful for fully internal deployments; will trip browser warnings on production names.',
        ],
        'order' => [
            'type' => 'string',
            'default' => '',
            'label' => 'Directive order override',
            'help' => 'Optional `order <directive> <before|after> <directive>` line for plugin-added directives. Leave blank for default ordering.',
        ],
    ];

    /**
     * `servers { ... }` inner block — global per-listener tuning.
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const SERVERS_PARAMS = [
        'protocols' => [
            'type' => 'string',
            'default' => 'h1 h2 h3',
            'label' => 'Protocols (servers.protocols)',
            'help' => 'Space-separated. Common values: `h1 h2 h3` (default), `h1 h2` (no QUIC).',
        ],
        'read_body' => [
            'type' => 'string',
            'default' => '',
            'label' => 'Read body timeout',
            'help' => 'Max time Caddy waits for the request body. e.g. `10s`, `1m`. Blank = use Caddy default.',
        ],
        'read_header' => [
            'type' => 'string',
            'default' => '',
            'label' => 'Read header timeout',
            'help' => 'Max time waiting for request headers. Typical: `5s`. Blank = default.',
        ],
        'write' => [
            'type' => 'string',
            'default' => '',
            'label' => 'Write timeout',
            'help' => 'Max time writing the response. Increase for large downloads. Blank = default.',
        ],
        'idle' => [
            'type' => 'string',
            'default' => '',
            'label' => 'Idle keep-alive timeout',
            'help' => 'How long an idle keep-alive connection stays open. e.g. `5m`. Blank = default.',
        ],
    ];

    /**
     * `log default { ... }` inner block — default access log config.
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const LOG_PARAMS = [
        'output' => [
            'type' => 'string',
            'default' => '',
            'label' => 'Log output',
            'help' => 'One of `stdout`, `stderr`, `discard`, or `file <path>` (e.g. `file /var/log/caddy/access.log`). Blank = stderr.',
        ],
        'format' => [
            'type' => 'string',
            'default' => '',
            'label' => 'Log format',
            'help' => 'One of `json` (default), `console`. Blank = json.',
        ],
        'level' => [
            'type' => 'string',
            'default' => '',
            'label' => 'Log level',
            'help' => 'One of `DEBUG`, `INFO`, `WARN`, `ERROR`. Blank = INFO.',
        ],
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

        $block = $this->extractGlobalBlock($contents);
        if ($block === null) {
            return ['values' => $values, 'exists' => false, 'unreadable' => false, 'raw' => ''];
        }

        foreach (self::TOP_PARAMS as $key => $meta) {
            $v = $this->extractTopScalar($block, $key);
            if ($v !== null) {
                $values[$key] = $v;
            }
        }
        foreach (self::SERVERS_PARAMS as $key => $meta) {
            if ($key === 'protocols') {
                $v = $this->extractInnerScalar($block, 'servers', 'protocols');
            } else {
                $v = $this->extractDoubleNestedScalar($block, 'servers', 'timeouts', $key);
            }
            if ($v !== null) {
                $values['servers_'.$key] = $v;
            }
        }
        foreach (self::LOG_PARAMS as $key => $meta) {
            $v = $this->extractInnerScalar($block, 'log default', $key);
            if ($v === null) {
                // Some operators write `log { ... }` without "default" — fall back.
                $v = $this->extractInnerScalar($block, 'log', $key);
            }
            if ($v !== null) {
                $values['log_'.$key] = $v;
            }
        }

        return ['values' => $values, 'exists' => true, 'unreadable' => false, 'raw' => $block];
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

        $emit->step('caddy-globals', 'Reading current Caddyfile');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read Caddyfile from the server.');
        }

        $renderedBlock = $this->renderBlock($values);
        $hadBlock = $this->extractGlobalBlock($contents) !== null;
        if ($hadBlock) {
            $emit->info('Existing global block found — replacing in place.');
            $newContents = preg_replace('/^\s*\{(?:[^{}]|\{[^{}]*\})*\}/s', rtrim($renderedBlock, "\n"), $contents, 1);
            if (! is_string($newContents)) {
                throw new \RuntimeException('Failed to rewrite the global block.');
            }
        } else {
            $emit->info('No global block on disk — prepending a new one.');
            $newContents = rtrim($renderedBlock, "\n")."\n\n".ltrim($contents, "\n");
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents);
        $emit->success('Caddy reloaded with the new global options.');
    }

    /**
     * Render the global `{ ... }` block from form values. Public so tests
     * can assert the formatter output without going through SSH.
     *
     * @param  array<string, mixed> $values
     */
    public function renderBlock(array $values): string
    {
        $lines = ['{'];

        // Top-level scalars (skip blanks so the file doesn't accumulate `key `
        // lines with empty values that Caddy would reject).
        foreach (self::TOP_PARAMS as $key => $meta) {
            $raw = trim((string) ($values[$key] ?? $meta['default']));
            if ($raw === '' || ($meta['type'] === 'bool' && $raw === '0')) {
                continue;
            }
            if ($meta['type'] === 'bool') {
                $lines[] = "\t".$key;

                continue;
            }
            $lines[] = "\t".$key.' '.$raw;
        }

        // servers { ... } block — emit only if any servers.* value is set.
        $serverDirectives = [];
        $protocols = trim((string) ($values['servers_protocols'] ?? ''));
        if ($protocols !== '') {
            $serverDirectives[] = "\t\tprotocols ".$protocols;
        }
        $timeoutDirectives = [];
        foreach (['read_body', 'read_header', 'write', 'idle'] as $tkey) {
            $tv = trim((string) ($values['servers_'.$tkey] ?? ''));
            if ($tv !== '') {
                $timeoutDirectives[] = "\t\t\t".$tkey.' '.$tv;
            }
        }
        if ($timeoutDirectives !== []) {
            $serverDirectives[] = "\t\ttimeouts {";
            foreach ($timeoutDirectives as $d) {
                $serverDirectives[] = $d;
            }
            $serverDirectives[] = "\t\t}";
        }
        if ($serverDirectives !== []) {
            $lines[] = "\tservers {";
            foreach ($serverDirectives as $d) {
                $lines[] = $d;
            }
            $lines[] = "\t}";
        }

        // log default { ... } block.
        $logDirectives = [];
        foreach (self::LOG_PARAMS as $key => $meta) {
            $lv = trim((string) ($values['log_'.$key] ?? ''));
            if ($lv === '') {
                continue;
            }
            $logDirectives[] = "\t\t".$key.' '.$lv;
        }
        if ($logDirectives !== []) {
            $lines[] = "\tlog default {";
            foreach ($logDirectives as $d) {
                $lines[] = $d;
            }
            $lines[] = "\t}";
        }

        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /**
     * Find the leading `{ ... }` block in the Caddyfile (Caddy's global
     * options). The opening brace must be the first non-comment, non-blank
     * thing in the file — Caddy doesn't allow it anywhere else.
     */
    private function extractGlobalBlock(string $contents): ?string
    {
        // Strip leading comments + blank lines so the regex can anchor.
        $offset = 0;
        $len = strlen($contents);
        while ($offset < $len) {
            $c = $contents[$offset];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $offset++;

                continue;
            }
            if ($c === '#') {
                $eol = strpos($contents, "\n", $offset);
                $offset = $eol === false ? $len : $eol + 1;

                continue;
            }
            break;
        }
        if ($offset >= $len || $contents[$offset] !== '{') {
            return null;
        }

        // Walk to the matching close brace, tracking depth.
        $depth = 0;
        for ($i = $offset; $i < $len; $i++) {
            $ch = $contents[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($contents, $offset, $i - $offset + 1);
                }
            } elseif ($ch === '#') {
                $eol = strpos($contents, "\n", $i);
                if ($eol === false) {
                    break;
                }
                $i = $eol;
            }
        }

        return null;
    }

    private function extractTopScalar(string $block, string $key): ?string
    {
        // Match `<key> <rest>` at top level (one tab indent in well-formatted
        // files, but be lenient on whitespace). Skip lines inside nested blocks
        // by tracking depth.
        $depth = 0;
        foreach (preg_split('/\R/', $block) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') {
                continue;
            }
            if ($depth === 1) {
                if (preg_match('/^'.preg_quote($key, '/').'(?:\s+(.+))?$/', $trim, $m) === 1) {
                    return isset($m[1]) ? trim($m[1]) : '1';
                }
            }
            // Adjust depth for this line's braces.
            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        return null;
    }

    private function extractInnerScalar(string $block, string $outerHeader, string $key): ?string
    {
        $innerBlock = $this->extractNamedInnerBlock($block, $outerHeader);
        if ($innerBlock === null) {
            return null;
        }
        foreach (preg_split('/\R/', $innerBlock) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') {
                continue;
            }
            if (preg_match('/^'.preg_quote($key, '/').'\s+(.+)$/', $trim, $m) === 1) {
                return trim($m[1]);
            }
        }

        return null;
    }

    private function extractDoubleNestedScalar(string $block, string $outerHeader, string $middleHeader, string $key): ?string
    {
        $inner = $this->extractNamedInnerBlock($block, $outerHeader);
        if ($inner === null) {
            return null;
        }
        $middle = $this->extractNamedInnerBlock($inner, $middleHeader);
        if ($middle === null) {
            return null;
        }
        foreach (preg_split('/\R/', $middle) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') {
                continue;
            }
            if (preg_match('/^'.preg_quote($key, '/').'\s+(.+)$/', $trim, $m) === 1) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /**
     * Extract the body of `<header> { ... }` from a Caddyfile block. The
     * header is matched on its first whitespace-trimmed line; the body is
     * everything between the opening and matching closing brace.
     */
    private function extractNamedInnerBlock(string $block, string $header): ?string
    {
        $pattern = '/^\s*'.preg_quote($header, '/').'\s*\{/m';
        if (preg_match($pattern, $block, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $bodyStart = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($block);
        for ($i = $bodyStart; $i < $len; $i++) {
            $c = $block[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($block, $bodyStart, $i - $bodyStart);
                }
            }
        }

        return null;
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
        foreach (self::SERVERS_PARAMS as $key => $meta) {
            $out['servers_'.$key] = (string) $meta['default'];
        }
        foreach (self::LOG_PARAMS as $key => $meta) {
            $out['log_'.$key] = (string) $meta['default'];
        }

        return $out;
    }

    private function stageInstallValidateReload(SshConnection $ssh, ConsoleEmitter $emit, string $newContents): void
    {
        $emit->step('caddy-globals', 'Staging new Caddyfile to /tmp');
        $tmpRemote = '/tmp/dply-caddyfile.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $ssh->exec(sprintf('printf %s | base64 -d | sudo -n tee %s > /dev/null', escapeshellarg($encoded), escapeshellarg($tmpRemote)), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new Caddyfile on the server');
            throw new \RuntimeException('Failed to stage the new Caddyfile.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('caddy-globals', 'Snapshotting current Caddyfile to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step('caddy-globals', 'Installing new Caddyfile at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous Caddyfile left in place');
            throw new \RuntimeException('Failed to install the new Caddyfile.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step('caddy-globals', 'Validating with `caddy validate`');
        $validate = $ssh->exec('sudo -n caddy validate --config '.escapeshellarg(self::REMOTE_PATH).' 2>&1; echo "__exit__:$?"', 30);
        $exit = (preg_match('/__exit__:(\d+)\s*$/', $validate, $vm) === 1) ? (int) $vm[1] : 1;
        $stripped = (string) preg_replace('/__exit__:\d+\s*$/', '', $validate);
        foreach (preg_split('/\R/', trim($stripped)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit($line, $exit !== 0 ? ConsoleAction::LEVEL_WARN : ConsoleAction::LEVEL_INFO);
            }
        }
        if ($exit !== 0) {
            $emit->step('caddy-globals', 'Validation failed — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $emit->error('Config validation failed; previous Caddyfile restored.');
            throw new \RuntimeException('Config validation failed; previous Caddyfile restored. caddy validate output:'."\n".trim($stripped));
        }
        $emit->success('Caddyfile validated.');

        $emit->step('caddy-globals', 'Reloading Caddy (`systemctl reload caddy`)');
        $reload = $ssh->exec('sudo -n systemctl reload caddy 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart caddy 2>&1', 30);
        }
    }
}
