<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;

/**
 * Detects the Horizon worker config a deployed app actually needs, so the
 * worker-pool UI can pre-fill "Queues watched" + the process knobs instead of
 * the operator typing them from memory.
 *
 * Two paths, tried in one SSH round-trip:
 *
 *   - package: if the app ships the open-source `dply-io/horizon-config`
 *     package, we run its `php artisan horizon:detect --json` in the deployed
 *     release and take its (authoritative) result verbatim. It reflects on the
 *     real Horizon supervisor config + every ShouldQueue class.
 *   - scan: otherwise we fall back to a coarse grep of config/horizon.php and
 *     the app's `onQueue()` / `$queue` usages, and size the pool here using the
 *     SAME arithmetic the package's ResourceSizer uses (kept in sync below —
 *     the package is the canonical implementation).
 *
 * Read-only and SSH-only (mirrors {@see SiteEnvRequirementScanner}): runs as the
 * deploy user, returns a plain array; the caller (queued job) persists it.
 */
class HorizonConfigDetector
{
    public const BALANCES = ['simple', 'auto', 'false'];

    /**
     * @return array{
     *     source: string,
     *     detected_at: string,
     *     environment: ?string,
     *     host: array{cpu_cores: int, ram_mb: int},
     *     queues: list<array{name: string, sources: list<string>, job_count: int}>,
     *     recommended: array{queues: list<string>, min_processes: int, max_processes: int, memory: int, timeout: int, tries: int, balance: string}
     * }
     */
    public function detect(Site $site): array
    {
        $server = $site->server;
        if ($server === null || ! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $root = rtrim($site->effectiveEnvDirectory(), '/');
        if ($root === '' || ! str_starts_with($root, '/')) {
            throw new \RuntimeException('Site has no resolvable repository path to scan.');
        }

        $output = (new SshConnection($server))->exec($this->buildScript($root), 120);

        return $this->parse($output);
    }

    /**
     * One SSH round-trip: read the host spec, try the package command, and emit
     * the grep fallback. Base64-encoded so the regexes don't fight SSH quoting,
     * exactly like {@see SiteEnvRequirementScanner::buildScript()}.
     */
    private function buildScript(string $root): string
    {
        $r = escapeshellarg($root);

        $excludes = "--include='*.php' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=storage --exclude-dir=.git";
        // A quoted queue token: the surrounding quote matched with `.` so the
        // pattern carries no quote chars; PHP pulls the name out afterwards.
        $onQueue = 'onQueue\\([[:space:]]*.[A-Za-z0-9_:.\\-]+';
        $queueProp = 'queue[[:space:]]*=[[:space:]]*.[A-Za-z0-9_:.\\-]+';

        $inner = implode("\n", [
            'set +e',
            'CORES=$(nproc 2>/dev/null || echo 1)',
            'MEMKB=$(grep -m1 MemTotal /proc/meminfo 2>/dev/null | awk "{print \$2}")',
            '[ -z "$MEMKB" ] && MEMKB=0',
            'RAMMB=$((MEMKB / 1024))',
            'echo DPLY_HOST_BEGIN',
            'echo "cores=$CORES"',
            'echo "ram_mb=$RAMMB"',
            'echo DPLY_HOST_END',
            // Package path: only if artisan exists. Capture exit + output.
            'PEC=1; PKG=""',
            'if [ -f '.$r.'/artisan ]; then PKG=$(cd '.$r.' && php artisan horizon:detect --json --cores="$CORES" --ram="$RAMMB" 2>/dev/null); PEC=$?; fi',
            'echo DPLY_PACKAGE_BEGIN',
            'if [ "$PEC" = "0" ] && printf "%s" "$PKG" | head -c1 | grep -q "{"; then printf "%s" "$PKG"; fi',
            'echo',
            'echo DPLY_PACKAGE_END',
            // Fallback grep: watched (horizon.php) + dispatched (onQueue / $queue).
            'echo DPLY_SCAN_BEGIN',
            '[ -f '.$r.'/config/horizon.php ] && grep -hoE "'.$queueProp.'" '.$r.'/config/horizon.php 2>/dev/null | sort -u',
            '[ -d '.$r.'/app ] && grep -rhoE "'.$onQueue.'" '.$excludes.' '.$r.'/app 2>/dev/null | sort -u',
            '[ -d '.$r.'/app ] && grep -rhoE "\\$'.$queueProp.'" '.$excludes.' '.$r.'/app 2>/dev/null | sort -u',
            'echo DPLY_SCAN_END',
        ]);

        return 'echo '.escapeshellarg(base64_encode($inner)).' | base64 -d | bash';
    }

    /**
     * @return array{
     *     source: string,
     *     detected_at: string,
     *     environment: ?string,
     *     host: array{cpu_cores: int, ram_mb: int},
     *     queues: list<array{name: string, sources: list<string>, job_count: int}>,
     *     recommended: array{queues: list<string>, min_processes: int, max_processes: int, memory: int, timeout: int, tries: int, balance: string}
     * }
     */
    private function parse(string $output): array
    {
        $host = $this->parseHost($this->section($output, 'HOST'));

        // Prefer the package's authoritative JSON when present and well-formed.
        $packageJson = trim($this->section($output, 'PACKAGE'));
        if ($packageJson !== '') {
            $decoded = json_decode($packageJson, true);
            if (is_array($decoded) && isset($decoded['recommended']['queues']) && is_array($decoded['recommended']['queues'])) {
                return $this->normalizePackageResult($decoded, $host);
            }
        }

        return $this->buildScanResult($this->section($output, 'SCAN'), $host);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array{cpu_cores: int, ram_mb: int}  $host
     * @return array{source: string, detected_at: string, environment: ?string, host: array{cpu_cores: int, ram_mb: int}, queues: list<array{name: string, sources: list<string>, job_count: int}>, recommended: array{queues: list<string>, min_processes: int, max_processes: int, memory: int, timeout: int, tries: int, balance: string}}
     */
    private function normalizePackageResult(array $decoded, array $host): array
    {
        $queues = [];
        foreach (($decoded['queues'] ?? []) as $q) {
            if (! is_array($q) || ! is_string($q['name'] ?? null)) {
                continue;
            }
            $name = $this->cleanQueueName($q['name']);
            if ($name === '') {
                continue;
            }
            $queues[] = [
                'name' => $name,
                'sources' => array_values(array_filter(array_map('strval', (array) ($q['sources'] ?? [])))),
                'job_count' => max(0, (int) ($q['job_count'] ?? 0)),
            ];
        }

        $rec = is_array($decoded['recommended'] ?? null) ? $decoded['recommended'] : [];
        $recQueues = $this->cleanQueueList($rec['queues'] ?? array_map(fn ($q) => $q['name'], $queues));

        return [
            'source' => 'package',
            'detected_at' => now()->toIso8601String(),
            'environment' => is_string($decoded['environment'] ?? null) ? $decoded['environment'] : null,
            'host' => is_array($decoded['host'] ?? null) ? $this->parseHostArray($decoded['host']) : $host,
            'queues' => $queues !== [] ? $queues : $this->queuesFromNames($recQueues),
            'recommended' => $this->normalizeRecommended($rec, $recQueues),
        ];
    }

    /**
     * @param  array{cpu_cores: int, ram_mb: int}  $host
     * @return array{source: string, detected_at: string, environment: ?string, host: array{cpu_cores: int, ram_mb: int}, queues: list<array{name: string, sources: list<string>, job_count: int}>, recommended: array{queues: list<string>, min_processes: int, max_processes: int, memory: int, timeout: int, tries: int, balance: string}}
     */
    private function buildScanResult(string $scanBlock, array $host): array
    {
        $names = [];
        foreach (preg_split('/\r\n|\r|\n/', $scanBlock) ?: [] as $line) {
            // Each grepped line carries one quoted queue token after a `.` quote.
            if (preg_match('/[\'"]([A-Za-z0-9_:.\-]+)[\'"]?$/', trim($line), $m) === 1) {
                $clean = $this->cleanQueueName($m[1]);
                if ($clean !== '') {
                    $names[$clean] = true;
                }
            }
        }

        $queues = array_keys($names);
        // The first queue is the dispatch target (REDIS_QUEUE); ensure 'default'
        // leads unless the app clearly never uses it.
        if (! in_array('default', $queues, true)) {
            array_unshift($queues, 'default');
        } else {
            $queues = array_merge(['default'], array_values(array_filter($queues, fn ($q) => $q !== 'default')));
        }

        return [
            'source' => 'scan',
            'detected_at' => now()->toIso8601String(),
            'environment' => null,
            'host' => $host,
            'queues' => $this->queuesFromNames($queues, ['scan']),
            'recommended' => $this->normalizeRecommended(
                $this->size(count($queues), $host['cpu_cores'], $host['ram_mb']),
                $queues,
            ),
        ];
    }

    /**
     * Right-size processes/memory from queue count + box spec. This MIRRORS
     * \Dply\HorizonConfig\ResourceSizer in the open-source package, which is the
     * canonical implementation — keep the two in step. (dply runs `composer
     * install --no-dev` on the box, so it can't hard-depend on the package yet;
     * once it's published this method delegates to it instead.)
     *
     * @return array{min_processes: int, max_processes: int, memory: int, timeout: int, balance: string}
     */
    private function size(int $queueCount, int $cpuCores, int $ramMb, int $workerMemoryMb = 128): array
    {
        $queueCount = max(1, $queueCount);
        $cpuCores = max(1, $cpuCores);
        $ramMb = max(0, $ramMb);
        $workerMemory = max(32, $workerMemoryMb);

        $min = max(1, min($queueCount, intdiv($cpuCores + 1, 2)));
        $softFloor = max(10, $queueCount * $min);
        $desired = max($softFloor, $queueCount * 3);
        $cpuCeiling = $cpuCores * 4;
        $ramCeiling = $ramMb > 0 ? max($min, intdiv((int) ($ramMb * 0.6), $workerMemory)) : $desired;

        $max = min(max($min, min($desired, $cpuCeiling, $ramCeiling)), 256);

        return [
            'min_processes' => $min,
            'max_processes' => $max,
            'memory' => $workerMemory,
            'timeout' => 720,
            'balance' => 'auto',
        ];
    }

    /**
     * @param  array<string, mixed>  $rec
     * @param  list<string>  $queues
     * @return array{queues: list<string>, min_processes: int, max_processes: int, memory: int, timeout: int, tries: int, balance: string}
     */
    private function normalizeRecommended(array $rec, array $queues): array
    {
        $min = $this->clampInt($rec['min_processes'] ?? 1, 1, 256);
        $max = $this->clampInt($rec['max_processes'] ?? 4, $min, 256);
        $balance = in_array($rec['balance'] ?? null, self::BALANCES, true) ? (string) $rec['balance'] : 'auto';

        return [
            'queues' => $queues !== [] ? $queues : ['default'],
            'min_processes' => $min,
            'max_processes' => $max,
            'memory' => $this->clampInt($rec['memory'] ?? 128, 32, 4096),
            'timeout' => $this->clampInt($rec['timeout'] ?? 720, 5, 3600),
            'tries' => $this->clampInt($rec['tries'] ?? 1, 1, 25),
            'balance' => $balance,
        ];
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $sources
     * @return list<array{name: string, sources: list<string>, job_count: int}>
     */
    private function queuesFromNames(array $names, array $sources = []): array
    {
        return array_map(fn (string $name) => [
            'name' => $name,
            'sources' => $sources,
            'job_count' => 0,
        ], $names);
    }

    /** @return array{cpu_cores: int, ram_mb: int} */
    private function parseHost(string $block): array
    {
        $cores = 1;
        $ram = 0;
        foreach (preg_split('/\r\n|\r|\n/', $block) ?: [] as $line) {
            if (preg_match('/^cores=(\d+)/', trim($line), $m) === 1) {
                $cores = max(1, (int) $m[1]);
            } elseif (preg_match('/^ram_mb=(\d+)/', trim($line), $m) === 1) {
                $ram = max(0, (int) $m[1]);
            }
        }

        return ['cpu_cores' => $cores, 'ram_mb' => $ram];
    }

    /**
     * @param  array<string, mixed>  $host
     * @return array{cpu_cores: int, ram_mb: int}
     */
    private function parseHostArray(array $host): array
    {
        return [
            'cpu_cores' => max(1, (int) ($host['cpu_cores'] ?? 1)),
            'ram_mb' => max(0, (int) ($host['ram_mb'] ?? 0)),
        ];
    }

    /**
     * @param  mixed  $queues
     * @return list<string>
     */
    private function cleanQueueList($queues): array
    {
        if (! is_array($queues)) {
            return [];
        }
        $out = [];
        foreach ($queues as $q) {
            $clean = $this->cleanQueueName(is_string($q) ? $q : '');
            if ($clean !== '' && ! in_array($clean, $out, true)) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    private function cleanQueueName(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_:\-.]/', '', trim($name));
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    /** Pull the text between DPLY_<NAME>_BEGIN/END out of the combined output. */
    private function section(string $output, string $name): string
    {
        $begin = strpos($output, 'DPLY_'.$name.'_BEGIN');
        $end = strpos($output, 'DPLY_'.$name.'_END');
        if ($begin === false || $end === false || $end < $begin) {
            return '';
        }
        $start = $begin + strlen('DPLY_'.$name.'_BEGIN');

        return trim(substr($output, $start, $end - $start));
    }
}
