<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Services\SshConnection;
use Illuminate\Support\Str;

/**
 * Runs a cron job command immediately over SSH (same wrapping as crontab uses).
 */
class ServerCronJobRunner
{
    public function __construct(
        protected ServerCronCommandBuilder $commandBuilder,
    ) {}

    /**
     * @param  callable(string):void|null  $onOutputChunk  Invoked for each SSH stdout/stderr chunk (live UI).
     */
    public function runNow(Server $server, ServerCronJob $job, int $timeoutSeconds = 300, ?callable $onOutputChunk = null): CronJobRunResult
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException(__('Server must be ready with an SSH key.'));
        }

        if (! $job->enabled) {
            throw new \RuntimeException(__('Enable this job before running it.'));
        }

        $segment = $this->commandBuilder->crontabCommandSegment($server, $job);
        if ($segment === '') {
            throw new \RuntimeException(__('Command is empty.'));
        }

        $ssh = new SshConnection($server);
        $wrapped = 'bash -lc '.escapeshellarg($segment);

        if ($onOutputChunk !== null) {
            [$output, $exit] = $ssh->execWithCallbackAndExit($wrapped, $onOutputChunk, $timeoutSeconds);
        } else {
            $output = $ssh->exec($wrapped, $timeoutSeconds);
            $exit = $ssh->lastExecExitCode();
        }

        $ssh->disconnect();

        $job->update([
            'last_run_at' => now(),
            'last_run_output' => Str::limit($output, 65535),
        ]);

        return new CronJobRunResult($output !== false ? $output : '', $exit);
    }

    /**
     * Local preview of the exact shell segment (nothing is executed on the server).
     */
    public function dryRunPreview(Server $server, ServerCronJob $job): string
    {
        $segment = $this->commandBuilder->crontabCommandSegment($server, $job);
        if ($segment === '') {
            throw new \RuntimeException(__('Command is empty.'));
        }

        return __('Nothing was executed. The worker would run this over SSH:')."\n\n".$segment;
    }
}
