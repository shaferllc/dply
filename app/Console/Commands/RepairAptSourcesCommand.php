<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\SshConnectionFactory;
use App\Support\Servers\AptSourceRepairScript;
use Illuminate\Console\Command;

/**
 * dply:server:apt-repair [server] [--all] [--dry-run]
 *
 * Removes apt sources that can never verify — an expired or revoked signing
 * key — from hosts that already carry one. The provision path prunes them
 * mid-run, but that only reaches a box someone re-provisions; every other host
 * keeps the dead source and fails every apt operation until something removes
 * it. repo.mysql.com's expired 2023 key is the case this was written for.
 *
 * Idempotent: a healthy host reports `ok` and is left untouched, so this is
 * safe to run across a fleet or on a schedule. Distro mirrors are never
 * removed.
 */
class RepairAptSourcesCommand extends Command
{
    protected $signature = 'dply:server:apt-repair
        {server? : Server name or ULID (omit when using --all)}
        {--all : Every ready VM server in the fleet}
        {--dry-run : Report what would be removed without removing anything}';

    protected $description = 'Detect and remove apt sources whose signing key can no longer be verified.';

    public function handle(SshConnectionFactory $ssh): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $servers = $this->resolveServers();

        if ($servers === []) {
            $this->error('No matching ready VM server. Pass a server name/ULID, or --all.');

            return self::FAILURE;
        }

        $script = AptSourceRepairScript::repairScript($dryRun);
        $rows = [];
        $failed = 0;

        foreach ($servers as $server) {
            $this->line("<fg=cyan>{$server->name}</> ({$server->id})");

            try {
                // The script exits non-zero for "nothing removed but apt is
                // still broken", which is information, not a transport error —
                // so the RESULT line is what we report, not the exit code.
                $output = $ssh->forServer($server)->exec($script, 300);
            } catch (\Throwable $e) {
                $this->getOutput()->writeln('  <fg=red>unreachable: '.$e->getMessage().'</>');
                $rows[] = [$server->name, 'unreachable'];
                $failed++;

                continue;
            }

            foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
                if (trim($line) !== '') {
                    $this->line('  '.$line);
                }
            }

            $result = $this->resultOf($output);
            $rows[] = [$server->name, $result];

            if (in_array($result, ['partial', 'no-action', 'unknown'], true)) {
                $failed++;
            }
        }

        $this->newLine();
        $this->table(['Server', 'Result'], $rows);

        if ($dryRun) {
            $this->info('Dry run — nothing was changed. Re-run without --dry-run to repair.');
        }

        // Non-zero when any host is still broken, so this is usable as a check.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * `ok` clean · `repaired` fixed · `would-repair` dry run found something ·
     * `partial` still failing after removal · `no-action` errors were not
     * signature failures.
     */
    private function resultOf(string $output): string
    {
        if (preg_match('/RESULT:\s*([a-z-]+)/', $output, $m) === 1) {
            return $m[1];
        }

        return 'unknown';
    }

    /** @return list<Server> */
    private function resolveServers(): array
    {
        $query = Server::query()->whereNotNull('ssh_private_key');

        if (! $this->option('all')) {
            $needle = (string) $this->argument('server');
            if ($needle === '') {
                return [];
            }
            $query->where(fn ($q) => $q->where('id', $needle)->orWhere('name', $needle));
        }

        return $query->get()
            ->filter(fn (Server $server) => $server->isVmHost() && $server->isReady())
            ->values()
            ->all();
    }
}
