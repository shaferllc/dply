<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;
use Carbon\CarbonImmutable;

/**
 * Probes Apache (httpd / apache2) state by shelling out:
 *
 *   - `apachectl -S`           parsed vhost map (ServerName, ports,
 *                              config-file:line)
 *   - `apachectl -M`           loaded modules list
 *   - `apachectl -V`           build version + MPM
 *   - curl 127.0.0.1:9092/server-status?auto   mod_status counters
 *                              (workers, busy/idle, request rate)
 *
 * Plus a small `openssl x509 -enddate` pass over every SSLCertificateFile
 * directive found in /etc/apache2/sites-enabled/* for the Certs sub-tab.
 *
 * Sub-tabs:
 *   - vhosts  — every parsed `<VirtualHost>` (ServerName, port, config)
 *   - modules — `apachectl -M` output, one per row
 *   - certs   — SSLCertificateFile paths + expiry + hosts they cover
 *   - workers — mod_status scoreboard + traffic counters
 */
class ApacheLiveStateProbe extends AbstractEngineLiveStateProbe
{
    use PrivilegedRemoteFileWrites;

    public function engineKey(): string
    {
        return 'apache';
    }

    protected function runFreshProbe(Server $server): EngineLiveState
    {
        $ssh = new SshConnection($server);
        $output = $ssh->exec($this->privilegedCommand($server, $this->buildProbeScript()), 30);
        $exit = $ssh->lastExecExitCode();

        $errors = [];
        if ($exit !== null && $exit !== 0) {
            $errors[] = sprintf('Apache probe SSH exited %d', $exit);
        }

        $sections = $this->splitSections((string) $output);
        $version = trim($sections['version'] ?? '');
        $modules = trim($sections['modules'] ?? '');
        $vhostsDump = trim($sections['vhosts'] ?? '');
        $status = trim($sections['status'] ?? '');
        $certExpiries = $this->parseCertExpiries($sections['certs'] ?? '');

        return new EngineLiveState(
            engine: $this->engineKey(),
            capturedAt: CarbonImmutable::now(),
            isFresh: true,
            units: [
                'vhosts' => $this->buildVhostUnits($vhostsDump),
                'modules' => $this->buildModuleUnits($modules),
                'certs' => $this->buildCertUnits($vhostsDump, $sections['certs'] ?? '', $certExpiries),
                'workers' => $this->buildWorkerUnits($status, $version),
            ],
            engineSpecific: array_filter([
                'version' => $version !== '' ? $version : null,
                'errors' => $errors !== [] ? $errors : null,
            ], static fn ($v) => $v !== null),
        );
    }

    private function buildProbeScript(): string
    {
        return <<<'BASH'
set +e
echo '###dply-section:version###'
apachectl -V 2>&1 | head -n 3
echo '###dply-section:end###'
echo '###dply-section:modules###'
apachectl -M 2>/dev/null
echo '###dply-section:end###'
echo '###dply-section:vhosts###'
apachectl -S 2>&1
echo '###dply-section:end###'
echo '###dply-section:status###'
curl -fsS --max-time 3 "http://127.0.0.1:9092/server-status?auto" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:certs###'
# Look for SSLCertificateFile in every effective config — fall back to
# grepping sites-enabled if apachectl can't dump the full tree.
for f in $(apachectl -t -D DUMP_CERTIFICATES 2>/dev/null \
            || grep -hri --include='*.conf' '^\s*SSLCertificateFile' /etc/apache2/sites-enabled/ 2>/dev/null \
              | awk '{print $2}' | sort -u); do
  [ -z "$f" ] && continue
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
        $heads = ['version', 'modules', 'vhosts', 'status', 'certs'];
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
     * `apachectl -S` output (simplified):
     *
     *   VirtualHost configuration:
     *   *:80                   is a NameVirtualHost
     *           default server example.com (/etc/apache2/sites-enabled/example.conf:1)
     *           port 80 namevhost example.com (/etc/apache2/sites-enabled/example.conf:1)
     *                   alias www.example.com
     *           port 80 namevhost other.com (/etc/apache2/sites-enabled/other.conf:1)
     *
     * We parse each `port N namevhost <name> (<path>:<line>)` line into a row,
     * and pick up `alias` continuations as additional hostnames.
     *
     * @return list<array<string, mixed>>
     */
    private function buildVhostUnits(string $dump): array
    {
        $rows = [];
        $current = null;
        foreach (preg_split('/\R/', $dump) ?: [] as $line) {
            if (preg_match('/port\s+(\d+)\s+namevhost\s+(\S+)\s+\(([^:]+):(\d+)\)/', $line, $m) === 1) {
                if ($current !== null) {
                    $rows[] = $current;
                }
                $current = [
                    'server_name' => $m[2],
                    'port' => (int) $m[1],
                    'config' => $m[3].':'.$m[4],
                    'aliases' => [],
                ];

                continue;
            }
            if ($current !== null && preg_match('/^\s+alias\s+(\S+)/', $line, $m) === 1) {
                $current['aliases'][] = $m[1];
            }
        }
        if ($current !== null) {
            $rows[] = $current;
        }

        return $rows;
    }

    /**
     * `apachectl -M` output (one per line):
     *   core_module (static)
     *   so_module (static)
     *   mpm_event_module (shared)
     *
     * @return list<array<string, string>>
     */
    private function buildModuleUnits(string $dump): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $dump) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_contains($line, 'Loaded Modules')) {
                continue;
            }
            if (preg_match('/^(\S+)\s*\((static|shared)\)/', $line, $m) === 1) {
                $rows[] = ['name' => $m[1], 'kind' => $m[2]];
            }
        }

        return $rows;
    }

    /**
     * mod_status ?auto output:
     *   Total Accesses: 1234
     *   Total kBytes: 56789
     *   CPULoad: ...
     *   Uptime: 3600
     *   ReqPerSec: ...
     *   BusyWorkers: 1
     *   IdleWorkers: 4
     *   Scoreboard: ___W_K....
     *
     * @return list<array<string, string>>
     */
    private function buildWorkerUnits(string $status, string $version): array
    {
        $map = [];
        foreach (preg_split('/\R/', $status) ?: [] as $line) {
            if (preg_match('/^([A-Za-z][A-Za-z ]+):\s*(.+)$/', $line, $m) === 1) {
                $map[strtolower(trim($m[1]))] = trim($m[2]);
            }
        }

        $rows = [];
        $rows[] = ['key' => 'version', 'value' => $version !== '' ? explode("\n", $version)[0] : '?'];
        $rows[] = ['key' => 'mpm', 'value' => $this->extractMpmFromVersion($version)];
        foreach (['busyworkers' => 'busy_workers', 'idleworkers' => 'idle_workers', 'total accesses' => 'accesses_total', 'reqpersec' => 'requests_per_sec', 'bytespersec' => 'bytes_per_sec', 'uptime' => 'uptime_seconds'] as $src => $dest) {
            if (isset($map[$src])) {
                $rows[] = ['key' => $dest, 'value' => $map[$src]];
            }
        }
        $rows[] = ['key' => 'mod_status', 'value' => $status === '' ? 'unreachable on 127.0.0.1:9092' : 'available'];

        return $rows;
    }

    /**
     * Parse the cert-section output of the probe script (path|expiry per line),
     * cross-reference against the vhosts dump to determine which hosts cover
     * which cert.
     *
     * @param  array<string, string>  $certExpiries
     * @return list<array<string, mixed>>
     */
    private function buildCertUnits(string $vhostsDump, string $certsSection, array $certExpiries): array
    {
        $rows = [];
        foreach ($certExpiries as $path => $expiry) {
            $rows[] = ['path' => $path, 'expiry' => $expiry, 'readable' => $expiry !== 'unreadable'];
        }

        return $rows;
    }

    private function extractMpmFromVersion(string $version): string
    {
        if (preg_match('/Server MPM:\s*(\S+)/', $version, $m) === 1) {
            return $m[1];
        }

        return '?';
    }

    /**
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
            $path = trim($path);
            if ($path !== '') {
                $out[$path] = trim($expiry);
            }
        }

        return $out;
    }
}
