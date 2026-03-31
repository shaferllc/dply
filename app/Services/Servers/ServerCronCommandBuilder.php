<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerCronJob;

/**
 * Builds the command segment that appears after the five cron fields in crontab,
 * and the same segment for ad-hoc "run now" over SSH.
 *
 * Crontab is installed for {@see Server::$ssh_user}. If the job targets another
 * user, the command is wrapped with {@code sudo -u …} (requires passwordless sudo
 * for that user in production).
 */
class ServerCronCommandBuilder
{
    public function crontabCommandSegment(Server $server, ServerCronJob $job): string
    {
        $cmd = $this->buildInnerShellCommand($job);
        if ($cmd === '') {
            return '';
        }

        $sshUser = trim((string) $server->ssh_user) ?: 'root';
        $runAs = trim((string) $job->user) ?: $sshUser;

        $segment = $runAs === $sshUser
            ? $cmd
            : 'sudo -u '.escapeshellarg($runAs).' -H -- /bin/sh -c '.escapeshellarg($cmd);

        if (($job->overlap_policy ?? 'allow') === 'skip_if_running') {
            $lock = '/tmp/dply-cron-'.$job->getKey().'.lock';

            return 'flock -n '.escapeshellarg($lock).' -c '.escapeshellarg($segment);
        }

        return $segment;
    }

    /**
     * Command body before sudo/flock (env prefix, TZ, raw command).
     */
    public function buildInnerShellCommand(ServerCronJob $job): string
    {
        $cmd = trim($job->command);
        if ($cmd === '') {
            return '';
        }

        $prefix = trim((string) ($job->env_prefix ?? ''));
        if ($prefix !== '') {
            $cmd = $prefix."\n".$cmd;
        }

        $tz = trim((string) ($job->schedule_timezone ?? ''));
        if ($tz !== '') {
            $cmd = 'export TZ='.escapeshellarg($tz)."\n".$cmd;
        }

        return $cmd;
    }
}
