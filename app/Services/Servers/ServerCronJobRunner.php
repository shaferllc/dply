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

        $lastError = null;
        $output = '';
        $exit = null;

        foreach ($this->sshLoginCandidates($server) as $loginUser) {
            try {
                $ssh = $this->makeConnection($server, $loginUser);
                $wrapped = 'bash -lc '.escapeshellarg($this->commandForLoginUser($server, $segment, $loginUser));

                if ($onOutputChunk !== null) {
                    [$output, $exit] = $ssh->execWithCallbackAndExit($wrapped, $onOutputChunk, $timeoutSeconds);
                } else {
                    $output = $ssh->exec($wrapped, $timeoutSeconds);
                    $exit = $ssh->lastExecExitCode();
                }

                $ssh->disconnect();
                $lastError = null;
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        if ($lastError !== null) {
            throw $lastError;
        }

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

    /**
     * @return list<string>
     */
    protected function sshLoginCandidates(Server $server): array
    {
        $deploy = trim((string) $server->ssh_user) ?: 'root';
        $useRoot = (bool) config('server_cron.use_root_ssh', true);
        $fallback = (bool) config('server_cron.fallback_to_deploy_user_ssh', true);

        if (! $useRoot || $deploy === 'root') {
            return [$deploy];
        }

        return $fallback ? ['root', $deploy] : ['root'];
    }

    protected function commandForLoginUser(Server $server, string $segment, string $loginUser): string
    {
        $sshUser = trim((string) $server->ssh_user) ?: 'root';

        if ($loginUser === 'root' && $sshUser !== 'root') {
            return 'sudo -u '.escapeshellarg($sshUser).' -H -- /bin/sh -lc '.escapeshellarg($segment);
        }

        return $segment;
    }

    protected function makeConnection(Server $server, string $loginUser): SshConnection
    {
        $role = $loginUser === 'root'
            ? SshConnection::ROLE_RECOVERY
            : SshConnection::ROLE_OPERATIONAL;

        return new SshConnection($server, $loginUser, $role);
    }
}
