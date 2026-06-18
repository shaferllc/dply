<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Commands;

use App\Models\Server;
use App\Services\Servers\ServerSshConnectionRunner;
use Illuminate\Console\Command;

/**
 * Reclaims disk on remote servers by age-pruning the task-runner working dir.
 *
 * Every command dply runs uploads a per-task script into ~/.dply-task-runner on
 * the box — a 32-char-id <id>.sh plus its <id>.log, and for backgrounded tasks a
 * <id>.sh.pid / task-<id>-original.sh family (see TaskDispatcher / Connection).
 * Nothing ever deletes them, so on a busy box (deploys, health sweeps, the
 * member-health sweep) the dir grows without bound.
 *
 * This is the remote counterpart to PruneLocalWorkspaceArtifactsCommand, which
 * only touches the control plane's storage/app scratch. Here the work is one
 * `find … -mmin +N -delete` per server, run as the deploy user (whose home holds
 * the files). The age guard is the safety: a script for an in-flight or recently
 * backgrounded task is younger than the window, so it's never deleted and the
 * prune can't race a running deploy.
 */
class PruneRemoteTaskRunnerCommand extends Command
{
    protected $signature = 'dply:prune-remote-task-runner
        {--dry-run : Report what would be removed without deleting}
        {--hours= : Override max age (hours) before a script/log is pruned}
        {--server= : Limit to a single server id}';

    protected $description = 'Reclaim disk on remote servers by age-pruning stale task-runner scripts/logs in ~/.dply-task-runner.';

    public function handle(ServerSshConnectionRunner $runner): int
    {
        if (! config('dply.remote_task_runner_prune.enabled', true)) {
            $this->info('Remote task-runner prune is disabled (DPLY_REMOTE_TASK_RUNNER_PRUNE_ENABLED).');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');

        $hoursOpt = $this->option('hours');
        $hours = $hoursOpt !== null && $hoursOpt !== ''
            ? (int) $hoursOpt
            : (int) config('dply.remote_task_runner_prune.max_age_hours', 48);
        $minutes = max(1, $hours) * 60;

        $query = Server::query()
            ->where('status', Server::STATUS_READY)
            ->whereNotNull('ip_address')
            ->whereNotNull('ssh_private_key');

        if ($serverId = $this->option('server')) {
            $query->whereKey($serverId);
        }

        $totalFiles = 0;
        $totalBytes = 0;
        $touched = 0;

        $query->chunkById(50, function ($servers) use ($runner, $dry, $minutes, &$totalFiles, &$totalBytes, &$touched): void {
            foreach ($servers as $server) {
                /** @var Server $server */
                if (empty($server->ssh_private_key)) {
                    continue;
                }

                try {
                    [$files, $bytes] = $this->pruneServer($runner, $server, $minutes, $dry);
                } catch (\Throwable $e) {
                    $this->warn(sprintf('  %s (#%s): skipped — %s', $server->name, $server->id, $e->getMessage()));

                    continue;
                }

                $touched++;
                $totalFiles += $files;
                $totalBytes += $bytes;

                if ($files > 0) {
                    $this->line(sprintf(
                        '  %s %d file%s (%s) on %s (#%s)',
                        $dry ? 'would prune' : 'pruned',
                        $files,
                        $files === 1 ? '' : 's',
                        $this->humanBytes($bytes),
                        $server->name,
                        $server->id,
                    ), null, 'v');
                }
            }
        });

        $this->components->info(sprintf(
            '%s %d file%s (%s) across %d server%s.',
            $dry ? 'Would prune' : 'Pruned',
            $totalFiles,
            $totalFiles === 1 ? '' : 's',
            $this->humanBytes($totalBytes),
            $touched,
            $touched === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    /**
     * Run the find on one server and return [filesPruned, bytesFreed].
     *
     * Connects as the deploy user (useRoot:false) — that's whose home holds the
     * task-runner dir. `-printf '%s\n'` emits each matched file's size before
     * `-delete` removes it, so summing the output gives bytes freed even on the
     * real run; dry-run omits -delete and just counts.
     *
     * @return array{0: int, 1: int}
     */
    private function pruneServer(ServerSshConnectionRunner $runner, Server $server, int $minutes, bool $dry): array
    {
        $delete = $dry ? '' : ' -delete';

        // $HOME resolves to the deploy user's home; restrict to maxdepth 1 so a
        // surprise subdir is left alone, and to the known script/log/pid suffixes.
        $script = <<<SH
            DIR="\$HOME/.dply-task-runner"
            [ -d "\$DIR" ] || { echo "0 0"; exit 0; }
            find "\$DIR" -maxdepth 1 -type f \\
                \\( -name '*.sh' -o -name '*.log' -o -name '*.pid' \\) \\
                -mmin +{$minutes} -printf '%s\\n'{$delete} \\
                | awk '{c++; b+=\$1} END {print c+0, b+0}'
            SH;

        $out = $runner->run(
            $server,
            fn ($ssh): string => trim((string) $ssh->exec($script, 120)),
            useRoot: false,
            fallbackToDeploy: true,
        );

        // Take the last non-empty line in case a login banner precedes output.
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), fn ($l) => $l !== ''));
        $last = end($lines) ?: '0 0';

        if (! preg_match('/^(\d+)\s+(\d+)$/', $last, $m)) {
            throw new \RuntimeException('unexpected output: '.$last);
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }
}
