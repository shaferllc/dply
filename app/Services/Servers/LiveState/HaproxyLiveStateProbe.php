<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;
use Carbon\CarbonImmutable;

/**
 * Probes HAProxy's live state via its admin stats socket
 * (/run/haproxy/admin.sock — already enabled in dply's haproxy.cfg
 * template). Runs four commands through `socat`:
 *
 *   - `show stat`         → CSV of every frontend/backend/server
 *   - `show ssl cert *`   → list of loaded SSL certs with expiry
 *   - `show info`         → runtime info (uptime, sessions, version)
 *   - `show pools`        → memory pools (low-level diagnostics)
 *
 * Output normalized into the four sub-tab unit arrays:
 *   frontends / backends / ssl / runtime.
 *
 * `socat` is the only non-standard dependency. The dply haproxy installer
 * doesn't currently install it, but most cloud images ship it as part of
 * the apt-update-recommends pool; if missing the probe lands an error
 * in engineSpecific['errors'].
 */
class HaproxyLiveStateProbe extends AbstractEngineLiveStateProbe
{
    use PrivilegedRemoteFileWrites;

    public function engineKey(): string
    {
        return 'haproxy';
    }

    protected function runFreshProbe(Server $server): EngineLiveState
    {
        $ssh = new SshConnection($server);
        $script = $this->buildProbeScript();
        $output = $ssh->exec($this->privilegedCommand($server, $script), 30);
        $exit = $ssh->lastExecExitCode();

        $errors = [];
        if ($exit !== null && $exit !== 0) {
            $errors[] = sprintf('HAProxy stats socket SSH exited %d', $exit);
        }

        $sections = $this->splitSections((string) $output);
        $statRows = $this->parseStatCsv($sections['stat'] ?? '');
        $info = $this->parseKeyValueLines($sections['info'] ?? '');
        $certs = $this->parseSslCertList($sections['ssl'] ?? '');
        $pools = $this->parsePoolsBlob($sections['pools'] ?? '');

        return new EngineLiveState(
            engine: $this->engineKey(),
            capturedAt: CarbonImmutable::now(),
            isFresh: true,
            units: [
                'frontends' => $this->buildFrontendUnits($statRows),
                'backends' => $this->buildBackendUnits($statRows),
                'ssl' => $certs,
                'runtime' => $this->buildRuntimeUnits($info, $pools),
            ],
            engineSpecific: $errors === [] ? [] : ['errors' => $errors],
        );
    }

    private function buildProbeScript(): string
    {
        return <<<'BASH'
set +e
SOCK=/run/haproxy/admin.sock
if [ ! -S "$SOCK" ]; then
  echo "[dply] haproxy admin socket missing at $SOCK" >&2
  exit 1
fi
if ! command -v socat >/dev/null 2>&1; then
  echo "[dply] socat not installed; cannot query haproxy stats socket" >&2
  exit 2
fi
echo '###dply-section:stat###'
echo "show stat" | socat - "UNIX-CONNECT:$SOCK"
echo '###dply-section:end###'
echo '###dply-section:info###'
echo "show info" | socat - "UNIX-CONNECT:$SOCK"
echo '###dply-section:end###'
echo '###dply-section:ssl###'
echo "show ssl cert" | socat - "UNIX-CONNECT:$SOCK"
echo '###dply-section:end###'
echo '###dply-section:pools###'
echo "show pools" | socat - "UNIX-CONNECT:$SOCK" 2>/dev/null
echo '###dply-section:end###'
BASH;
    }

    /**
     * @return array<string, string>
     */
    private function splitSections(string $output): array
    {
        $heads = ['stat', 'info', 'ssl', 'pools'];
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
     * HAProxy's `show stat` CSV: first line is `# pxname,svname,qcur,...`.
     * Each subsequent line is one row; svname=FRONTEND/BACKEND/<server>.
     *
     * @return list<array<string, string>>
     */
    private function parseStatCsv(string $blob): array
    {
        $lines = preg_split('/\r\n|\n/', trim($blob)) ?: [];
        if ($lines === []) {
            return [];
        }
        $header = $lines[0];
        $header = ltrim($header, '#');
        $cols = array_map('trim', explode(',', $header));
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = explode(',', $line);
            $row = [];
            foreach ($cols as $i => $col) {
                if ($col === '') {
                    continue;
                }
                $row[$col] = (string) ($values[$i] ?? '');
            }
            if (! empty($row['pxname']) && ! empty($row['svname'])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * `show info` returns key:value lines. Trivially parsed.
     *
     * @return array<string, string>
     */
    private function parseKeyValueLines(string $blob): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n/', $blob) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            if ($k !== '') {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * `show ssl cert` lists cert file paths line-by-line. v1 surfaces the
     * paths; expiry / SAN extraction would need `show ssl cert <path>`
     * per cert (a follow-up enhancement).
     *
     * @return list<array<string, mixed>>
     */
    private function parseSslCertList(string $blob): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n/', trim($blob)) ?: [] as $line) {
            $line = trim($line);
            // Lines look like `# filename` for the header, then plain paths.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $out[] = [
                'path' => $line,
            ];
        }

        return $out;
    }

    /**
     * `show pools` returns one block of memory-pool usage. v1 stores the
     * raw blob (truncated) so the Runtime sub-tab can show it as-is —
     * structured parsing isn't worth the complexity for an advanced
     * diagnostic surface.
     */
    private function parsePoolsBlob(string $blob): string
    {
        $trimmed = trim($blob);

        return strlen($trimmed) > 8000 ? substr($trimmed, 0, 8000)."\n…(truncated)" : $trimmed;
    }

    /**
     * @param  list<array<string, string>>  $statRows
     * @return list<array<string, mixed>>
     */
    private function buildFrontendUnits(array $statRows): array
    {
        $rows = [];
        foreach ($statRows as $r) {
            if (($r['svname'] ?? '') !== 'FRONTEND') {
                continue;
            }
            $rows[] = [
                'name' => $r['pxname'],
                'status' => $r['status'] ?? '',
                'sessions_current' => (int) ($r['scur'] ?? 0),
                'sessions_max' => (int) ($r['smax'] ?? 0),
                'sessions_total' => (int) ($r['stot'] ?? 0),
                'rate' => (int) ($r['rate'] ?? 0),
                'rate_max' => (int) ($r['rate_max'] ?? 0),
                'hrsp_2xx' => (int) ($r['hrsp_2xx'] ?? 0),
                'hrsp_5xx' => (int) ($r['hrsp_5xx'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Backends rolled up with their member servers. `svname` is BACKEND
     * for the rollup row; individual server rows have svname=<server name>.
     *
     * @param  list<array<string, string>>  $statRows
     * @return list<array<string, mixed>>
     */
    private function buildBackendUnits(array $statRows): array
    {
        $byBackend = [];
        foreach ($statRows as $r) {
            $px = $r['pxname'] ?? '';
            $sv = $r['svname'] ?? '';
            if ($px === '' || $sv === 'FRONTEND') {
                continue;
            }
            $byBackend[$px] ??= ['name' => $px, 'status' => '', 'servers' => []];
            if ($sv === 'BACKEND') {
                $byBackend[$px]['status'] = $r['status'] ?? '';
                $byBackend[$px]['sessions_current'] = (int) ($r['scur'] ?? 0);
                $byBackend[$px]['sessions_total'] = (int) ($r['stot'] ?? 0);
                $byBackend[$px]['hrsp_5xx'] = (int) ($r['hrsp_5xx'] ?? 0);
            } else {
                $byBackend[$px]['servers'][] = [
                    'name' => $sv,
                    'status' => $r['status'] ?? '',
                    'check_status' => $r['check_status'] ?? '',
                    'sessions_current' => (int) ($r['scur'] ?? 0),
                    'sessions_total' => (int) ($r['stot'] ?? 0),
                ];
            }
        }

        return array_values($byBackend);
    }

    /**
     * Single-row table (or fewer fields when info is empty). Surfaces
     * what operators usually care about from `show info`.
     *
     * @param  array<string, string>  $info
     */
    private function buildRuntimeUnits(array $info, string $pools): array
    {
        if ($info === [] && $pools === '') {
            return [];
        }

        return [[
            'version' => $info['Version'] ?? '?',
            'uptime_sec' => (int) ($info['Uptime_sec'] ?? 0),
            'current_conns' => (int) ($info['CurrConns'] ?? 0),
            'cum_conns' => (int) ($info['CumConns'] ?? 0),
            'cum_req' => (int) ($info['CumReq'] ?? 0),
            'process_num' => (int) ($info['Process_num'] ?? 0),
            'pool_summary' => $pools,
        ]];
    }
}
