<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Quick "can this box even handle the install?" probe. One SSH round-trip pulls available memory
 * and root-disk space; the result is compared to per-engine thresholds defined in
 * `config/server_resources.php`.
 *
 * Used by the install jobs (Cache + Database engines) BEFORE doing apt-get magic, so a too-small
 * server fails fast with a clear error instead of OOM-killing during an apt install.
 */
class ServerResourcePreflight
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * @param  array{min_ram_mb: int, min_disk_mb: int}  $requirements
     * @return array{ok: bool, reason: ?string, available_ram_mb: ?int, available_disk_mb: ?int, required_ram_mb: int, required_disk_mb: int}
     */
    public function check(Server $server, array $requirements): array
    {
        $minRam = max(0, (int) ($requirements['min_ram_mb'] ?? 0));
        $minDisk = max(0, (int) ($requirements['min_disk_mb'] ?? 0));

        // `free -m` line 2 (after header): "Mem: total used free shared buff/cache available".
        // We use the `available` column (col 7) — what Linux can hand out without swapping.
        // `df -BM /` line 2: "Filesystem 1M-blocks Used Available Use% Mounted on" — we want col 4
        // and strip the trailing 'M'.
        $script = <<<'BASH'
set -e
RAM_AVAILABLE=$(free -m | awk '/^Mem:/ {print $7}')
DISK_AVAILABLE=$(df -BM / | awk 'NR==2 {gsub("M","",$4); print $4}')
echo "ram_mb=${RAM_AVAILABLE}"
echo "disk_mb=${DISK_AVAILABLE}"
BASH;

        try {
            $output = $this->executor->runInlineBash(
                $server,
                'server-resources:preflight',
                $script,
                timeoutSeconds: 30,
                asRoot: false,
            );
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'reason' => 'Could not read server resources: '.$e->getMessage(),
                'available_ram_mb' => null,
                'available_disk_mb' => null,
                'required_ram_mb' => $minRam,
                'required_disk_mb' => $minDisk,
            ];
        }

        if ($output->exitCode !== 0) {
            return [
                'ok' => false,
                'reason' => 'Resource probe exited '.$output->exitCode.': '.trim($output->buffer),
                'available_ram_mb' => null,
                'available_disk_mb' => null,
                'required_ram_mb' => $minRam,
                'required_disk_mb' => $minDisk,
            ];
        }

        $availableRam = null;
        $availableDisk = null;
        foreach (explode("\n", $output->buffer) as $line) {
            $line = trim($line);
            if (preg_match('/^ram_mb=(\d+)$/', $line, $m) === 1) {
                $availableRam = (int) $m[1];
            } elseif (preg_match('/^disk_mb=(\d+)$/', $line, $m) === 1) {
                $availableDisk = (int) $m[1];
            }
        }

        if ($availableRam === null || $availableDisk === null) {
            return [
                'ok' => false,
                'reason' => 'Resource probe returned unexpected output: '.trim($output->buffer),
                'available_ram_mb' => $availableRam,
                'available_disk_mb' => $availableDisk,
                'required_ram_mb' => $minRam,
                'required_disk_mb' => $minDisk,
            ];
        }

        if ($availableRam < $minRam) {
            return [
                'ok' => false,
                'reason' => sprintf(
                    'Insufficient memory: requires %d MB, have %d MB available.',
                    $minRam,
                    $availableRam,
                ),
                'available_ram_mb' => $availableRam,
                'available_disk_mb' => $availableDisk,
                'required_ram_mb' => $minRam,
                'required_disk_mb' => $minDisk,
            ];
        }

        if ($availableDisk < $minDisk) {
            return [
                'ok' => false,
                'reason' => sprintf(
                    'Insufficient disk: requires %d MB, have %d MB available on /.',
                    $minDisk,
                    $availableDisk,
                ),
                'available_ram_mb' => $availableRam,
                'available_disk_mb' => $availableDisk,
                'required_ram_mb' => $minRam,
                'required_disk_mb' => $minDisk,
            ];
        }

        return [
            'ok' => true,
            'reason' => null,
            'available_ram_mb' => $availableRam,
            'available_disk_mb' => $availableDisk,
            'required_ram_mb' => $minRam,
            'required_disk_mb' => $minDisk,
        ];
    }

    /**
     * @return array{min_ram_mb: int, min_disk_mb: int}
     */
    public static function requirementsForCacheEngine(string $engine): array
    {
        return self::requirementsFromConfig('server_resources.cache_services', $engine);
    }

    /**
     * @return array{min_ram_mb: int, min_disk_mb: int}
     */
    public static function requirementsForDatabaseEngine(string $engine): array
    {
        return self::requirementsFromConfig('server_resources.database_engines', $engine);
    }

    /**
     * @return array{min_ram_mb: int, min_disk_mb: int}
     */
    private static function requirementsFromConfig(string $configKey, string $engine): array
    {
        /** @var array<string, array{min_ram_mb?: int, min_disk_mb?: int}> $map */
        $map = (array) config($configKey, []);
        $row = $map[$engine] ?? $map['_default'] ?? ['min_ram_mb' => 0, 'min_disk_mb' => 0];

        return [
            'min_ram_mb' => (int) ($row['min_ram_mb'] ?? 0),
            'min_disk_mb' => (int) ($row['min_disk_mb'] ?? 0),
        ];
    }
}
