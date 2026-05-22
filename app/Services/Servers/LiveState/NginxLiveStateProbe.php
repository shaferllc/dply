<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;
use Carbon\CarbonImmutable;

/**
 * Probes nginx's live state. nginx doesn't expose an admin API like Caddy
 * or Traefik, so we shell out for everything:
 *
 *   - `nginx -T`        flattened config (every server / upstream / location)
 *   - `nginx -V`        build version + compiled-in modules
 *   - `nginx -t`        syntax check (also reports prefix path)
 *   - curl 127.0.0.1:9091/   stub_status (workers + connection counters,
 *                            wired up by dply's webserver-stats backfill)
 *
 * Parsed into four sub-tabs:
 *   - hosts     — every `server {}` block with its server_name + listen +
 *                 root + first fastcgi_pass / proxy_pass upstream
 *   - upstreams — every `upstream <name> { server <addr>; ... }` block
 *   - certs     — ssl_certificate paths across all server blocks, with
 *                 the openssl-derived "Not After" expiry when readable
 *   - workers   — stub_status counters (active conns, accepts, handled,
 *                 requests, reading/writing/waiting)
 */
class NginxLiveStateProbe extends AbstractEngineLiveStateProbe
{
    use PrivilegedRemoteFileWrites;

    public function engineKey(): string
    {
        return 'nginx';
    }

    protected function runFreshProbe(Server $server): EngineLiveState
    {
        $ssh = new SshConnection($server);
        $output = $ssh->exec($this->privilegedCommand($server, $this->buildProbeScript()), 30);
        $exit = $ssh->lastExecExitCode();

        $errors = [];
        if ($exit !== null && $exit !== 0) {
            $errors[] = sprintf('nginx probe SSH exited %d', $exit);
        }

        $sections = $this->splitSections((string) $output);
        $configDump = $sections['config'] ?? '';
        $version = trim($sections['version'] ?? '');
        $testOutput = trim($sections['test'] ?? '');
        $stubStatus = trim($sections['status'] ?? '');
        $certExpiries = $this->parseCertExpiries($sections['certs'] ?? '');

        return new EngineLiveState(
            engine: $this->engineKey(),
            capturedAt: CarbonImmutable::now(),
            isFresh: true,
            units: [
                'hosts' => $this->buildHostUnits($configDump),
                'upstreams' => $this->buildUpstreamUnits($configDump),
                'certs' => $this->buildCertUnits($configDump, $certExpiries),
                'workers' => $this->buildWorkerUnits($stubStatus, $version),
            ],
            engineSpecific: array_filter([
                'version' => $version !== '' ? $version : null,
                'test_output' => $testOutput !== '' ? $testOutput : null,
                'errors' => $errors !== [] ? $errors : null,
            ], static fn ($v) => $v !== null),
        );
    }

    private function buildProbeScript(): string
    {
        return <<<'BASH'
set +e
echo '###dply-section:version###'
nginx -V 2>&1 | head -n 1
echo '###dply-section:end###'
echo '###dply-section:test###'
nginx -t 2>&1
echo '###dply-section:end###'
echo '###dply-section:config###'
nginx -T 2>/dev/null
echo '###dply-section:end###'
echo '###dply-section:status###'
curl -fsS --max-time 3 http://127.0.0.1:9091/ 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:certs###'
# For each ssl_certificate path mentioned in the flattened config, dump its
# "Not After" + the path itself. openssl handles both single-cert and chain
# PEMs; we read the first cert from the file (the leaf).
for f in $(nginx -T 2>/dev/null | awk '/^\s*ssl_certificate\s/ {gsub(";",""); print $2}' | sort -u); do
  if [ -r "$f" ]; then
    expiry=$(openssl x509 -enddate -noout -in "$f" 2>/dev/null | sed 's/notAfter=//')
    echo "$f|$expiry"
  else
    echo "$f|unreadable"
  fi
done
echo '###dply-section:end###'
BASH;
    }

    /**
     * @return array<string, string>
     */
    private function splitSections(string $output): array
    {
        $heads = ['version', 'test', 'config', 'status', 'certs'];
        $end = '###dply-section:end###';
        $out = [];
        foreach ($heads as $name) {
            $head = '###dply-section:'.$name.'###';
            $start = strpos($output, $head);
            if ($start === false) {
                continue;
            }
            $start += strlen($head);
            $stop = strpos($output, $end, $start);
            $out[$name] = $stop === false ? substr($output, $start) : substr($output, $start, $stop - $start);
        }

        return $out;
    }

    /**
     * Pull every `server { ... }` block out of the flattened config. nginx -T
     * emits include-resolved blocks one after another; a tiny state machine
     * tracks brace depth so nested location {} blocks don't fool the parser.
     *
     * @return list<array<string, mixed>>
     */
    private function buildHostUnits(string $configDump): array
    {
        $blocks = $this->extractTopLevelBlocks($configDump, 'server');
        $rows = [];
        foreach ($blocks as $block) {
            $serverNames = $this->extractDirectiveTokens($block, 'server_name');
            $listens = $this->extractDirectiveTokens($block, 'listen');
            $root = $this->extractFirstDirectiveValue($block, 'root');
            $ssl = $this->blockHasSsl($block, $listens);
            $upstream = $this->extractUpstreamHint($block);

            $rows[] = [
                'server_names' => $serverNames,
                'listen' => $listens,
                'root' => $root ?? '',
                'ssl' => $ssl,
                'upstream' => $upstream,
            ];
        }

        return $rows;
    }

    /**
     * Pull every `upstream <name> { ... }` block.
     *
     * @return list<array<string, mixed>>
     */
    private function buildUpstreamUnits(string $configDump): array
    {
        // Custom regex here (vs extractTopLevelBlocks) because we want the
        // upstream NAME from the header.
        $rows = [];
        if (preg_match_all('/^\s*upstream\s+(\S+)\s*\{/m', $configDump, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        foreach ($matches[0] ?? [] as $i => $headerMatch) {
            $name = $matches[1][$i][0];
            $bodyStart = $headerMatch[1] + strlen($headerMatch[0]);
            $body = $this->captureBalancedBody($configDump, $bodyStart);
            if ($body === null) {
                continue;
            }
            $servers = [];
            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                if (preg_match('/^\s*server\s+(\S+)/', $line, $m) === 1) {
                    $servers[] = rtrim($m[1], ';');
                }
            }
            $rows[] = [
                'name' => $name,
                'servers' => $servers,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $certExpiries  path → expiry-or-error
     * @return list<array<string, mixed>>
     */
    private function buildCertUnits(string $configDump, array $certExpiries): array
    {
        $blocks = $this->extractTopLevelBlocks($configDump, 'server');
        $byPath = [];
        foreach ($blocks as $block) {
            $serverNames = $this->extractDirectiveTokens($block, 'server_name');
            $certPaths = $this->extractDirectiveTokens($block, 'ssl_certificate');
            foreach ($certPaths as $path) {
                if (! isset($byPath[$path])) {
                    $byPath[$path] = ['path' => $path, 'expiry' => $certExpiries[$path] ?? '?', 'hosts' => []];
                }
                $byPath[$path]['hosts'] = array_values(array_unique(array_merge($byPath[$path]['hosts'], $serverNames)));
            }
        }

        return array_values($byPath);
    }

    /**
     * stub_status output looks like:
     *   Active connections: 3
     *   server accepts handled requests
     *    1234 1234 5678
     *   Reading: 0 Writing: 1 Waiting: 2
     *
     * @return list<array<string, string>>
     */
    private function buildWorkerUnits(string $stubStatus, string $version): array
    {
        $active = '?';
        $accepts = $handled = $requests = '?';
        $reading = $writing = $waiting = '?';

        if (preg_match('/Active connections:\s*(\d+)/', $stubStatus, $m) === 1) {
            $active = $m[1];
        }
        if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s*$/m', $stubStatus, $m) === 1) {
            $accepts = $m[1];
            $handled = $m[2];
            $requests = $m[3];
        }
        if (preg_match('/Reading:\s*(\d+)\s+Writing:\s*(\d+)\s+Waiting:\s*(\d+)/', $stubStatus, $m) === 1) {
            $reading = $m[1];
            $writing = $m[2];
            $waiting = $m[3];
        }

        return [
            ['key' => 'version', 'value' => $version !== '' ? $version : '?'],
            ['key' => 'active_connections', 'value' => $active],
            ['key' => 'accepts_total', 'value' => $accepts],
            ['key' => 'handled_total', 'value' => $handled],
            ['key' => 'requests_total', 'value' => $requests],
            ['key' => 'reading', 'value' => $reading],
            ['key' => 'writing', 'value' => $writing],
            ['key' => 'waiting', 'value' => $waiting],
            ['key' => 'stub_status', 'value' => $stubStatus === '' ? 'unreachable on 127.0.0.1:9091' : 'available'],
        ];
    }

    /**
     * Pull every top-level `<name> { ... }` block out of nginx -T output.
     * Tracks brace depth so nested location {}'s inside server {}'s don't
     * cause a premature close.
     *
     * @return list<string>
     */
    private function extractTopLevelBlocks(string $configDump, string $name): array
    {
        $out = [];
        if (preg_match_all('/^[\t ]*'.preg_quote($name, '/').'\s*\{/m', $configDump, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        foreach ($matches[0] ?? [] as $headerMatch) {
            $bodyStart = $headerMatch[1] + strlen($headerMatch[0]);
            $body = $this->captureBalancedBody($configDump, $bodyStart);
            if ($body !== null) {
                $out[] = $body;
            }
        }

        return $out;
    }

    private function captureBalancedBody(string $haystack, int $offset): ?string
    {
        $depth = 1;
        $len = strlen($haystack);
        $start = $offset;
        for ($i = $offset; $i < $len; $i++) {
            $c = $haystack[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($haystack, $start, $i - $start);
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractDirectiveTokens(string $block, string $directive): array
    {
        $tokens = [];
        if (preg_match_all('/^[\t ]*'.preg_quote($directive, '/').'\s+([^;]+);/m', $block, $matches) === false) {
            return [];
        }
        foreach ($matches[1] ?? [] as $val) {
            foreach (preg_split('/\s+/', trim($val)) ?: [] as $t) {
                if ($t !== '') {
                    $tokens[] = $t;
                }
            }
        }

        return $tokens;
    }

    private function extractFirstDirectiveValue(string $block, string $directive): ?string
    {
        if (preg_match('/^[\t ]*'.preg_quote($directive, '/').'\s+([^;]+);/m', $block, $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    /**
     * @param  list<string>  $listens
     */
    private function blockHasSsl(string $block, array $listens): bool
    {
        if (preg_match('/^[\t ]*ssl_certificate\s+/m', $block) === 1) {
            return true;
        }
        foreach ($listens as $tok) {
            if (str_contains($tok, 'ssl') || $tok === '443') {
                return true;
            }
        }

        return false;
    }

    /**
     * The first fastcgi_pass or proxy_pass found in the server block, so the
     * operator can see "which backend" this vhost uses without drilling.
     */
    private function extractUpstreamHint(string $block): string
    {
        foreach (['fastcgi_pass', 'proxy_pass', 'uwsgi_pass'] as $d) {
            $v = $this->extractFirstDirectiveValue($block, $d);
            if ($v !== null) {
                return $v;
            }
        }

        return '';
    }

    /**
     * Parse the cert-section output of the probe script (path|expiry per line).
     *
     * @return array<string, string>
     */
    private function parseCertExpiries(string $section): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim($section)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            [$path, $expiry] = array_pad(explode('|', $line, 2), 2, '');
            $out[trim($path)] = trim($expiry);
        }

        return $out;
    }
}
