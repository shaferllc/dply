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
     * Command body before sudo/flock (env prefix, TZ, raw command). The output
     * is guaranteed single-line — every crontab entry must fit on one line, so
     * `env_prefix` (a multi-line `export …` block in the UI), `schedule_timezone`,
     * and the command itself are flattened with `; ` separators. Blank lines and
     * `#` shell comments inside env_prefix/command are dropped so they can't be
     * mistaken for crontab directives once the synchronizer concatenates them
     * after the five schedule fields.
     */
    public function buildInnerShellCommand(ServerCronJob $job): string
    {
        $parts = [];

        $tz = trim((string) ($job->schedule_timezone ?? ''));
        if ($tz !== '') {
            $parts[] = 'export TZ='.escapeshellarg($tz);
        }

        $prefix = $this->flattenMultilineToShell((string) ($job->env_prefix ?? ''));
        if ($prefix !== '') {
            $parts[] = $prefix;
        }

        $cmd = $this->flattenMultilineToShell((string) $job->command);
        if ($cmd === '') {
            return '';
        }
        $parts[] = $cmd;

        return implode('; ', $parts);
    }

    /**
     * Multi-line text → single-line shell. Drops blank lines and shell comments,
     * trims each statement, strips trailing `;` so the join doesn't double up.
     * Returns '' for input that contains nothing but blanks/comments.
     */
    private function flattenMultilineToShell(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $lines = preg_split('/\r?\n/', $body) ?: [];
        $cleaned = [];
        foreach ($lines as $line) {
            $statement = rtrim(trim($line), ';');
            if ($statement === '' || str_starts_with($statement, '#')) {
                continue;
            }
            $cleaned[] = $statement;
        }

        return implode('; ', $cleaned);
    }
}
