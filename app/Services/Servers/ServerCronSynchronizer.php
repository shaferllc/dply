<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\Site;
use App\Services\SshConnection;
use Illuminate\Support\Collection;

class ServerCronSynchronizer
{
    public function sync(Server $server, ?Collection $onlyJobs = null): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $jobs = $onlyJobs ?? $server->cronJobs;
        if ($jobs->isEmpty()) {
            return 'No cron jobs to sync.';
        }

        $markerBegin = '# BEGIN DPLY MANAGED';
        $markerEnd = '# END DPLY MANAGED';
        $schedBegin = '# BEGIN DPLY LARAVEL SCHEDULER';
        $schedEnd = '# END DPLY LARAVEL SCHEDULER';

        $ssh = new SshConnection($server);
        $current = $ssh->exec('crontab -l 2>/dev/null || true', 30);

        $before = $this->stripManagedBlock($current, $markerBegin, $markerEnd);
        $before = $this->stripManagedBlock($before, $schedBegin, $schedEnd);
        $before = rtrim($before)."\n\n";

        $builder = app(ServerCronCommandBuilder::class);

        $server->loadMissing('organization');
        $maintenanceUntil = $server->organization?->cron_maintenance_until;

        $block = $markerBegin."\n";
        if ($maintenanceUntil !== null && now()->lt($maintenanceUntil)) {
            $note = trim((string) $server->organization?->cron_maintenance_note);
            $block .= '# DPLY: cron jobs paused until '.$maintenanceUntil->toIso8601String()
                .($note !== '' ? ' — '.$note : '')."\n";
        } else {
            foreach ($jobs as $job) {
                /** @var ServerCronJob $job */
                if (! $job->enabled) {
                    continue;
                }
                $segment = $builder->crontabCommandSegment($server, $job);
                if ($segment === '') {
                    continue;
                }
                $block .= trim($job->cron_expression).' '.$segment."\n";
            }
        }
        $block .= $markerEnd."\n";

        $schedBlock = $this->buildLaravelSchedulerBlock($server);

        $newCrontab = $before.$block.$schedBlock;
        $tmp = '/tmp/dply_crontab_'.bin2hex(random_bytes(6));
        $ssh->putFile($tmp, $newCrontab);
        $out = $ssh->exec(
            'crontab '.escapeshellarg($tmp).' 2>&1; ec=$?; rm -f '.escapeshellarg($tmp).'; echo DPLY_CRON_EXIT:$ec',
            60
        );
        $ok = (bool) preg_match('/DPLY_CRON_EXIT:0\s*$/', $out);

        foreach ($jobs as $job) {
            $job->update([
                'is_synced' => $ok,
                'last_sync_error' => $ok ? null : $out,
            ]);
        }

        return $out;
    }

    protected function stripManagedBlock(string $crontab, string $begin, string $end): string
    {
        if (! str_contains($crontab, $begin)) {
            return $crontab;
        }

        $pattern = '/'.preg_quote($begin, '/').'.*?'.preg_quote($end, '/').'\s*/s';

        return trim(preg_replace($pattern, '', $crontab) ?? $crontab);
    }

    protected function buildLaravelSchedulerBlock(Server $server): string
    {
        $sites = Site::query()
            ->where('server_id', $server->id)
            ->where('laravel_scheduler', true)
            ->get();

        if ($sites->isEmpty()) {
            return '';
        }

        $lines = "# BEGIN DPLY LARAVEL SCHEDULER\n";
        foreach ($sites as $site) {
            $dir = $site->effectiveEnvDirectory();
            $lines .= '* * * * * cd '.escapeshellarg($dir).' && php artisan schedule:run >> /dev/null 2>&1'."\n";
        }
        $lines .= "# END DPLY LARAVEL SCHEDULER\n";

        return $lines;
    }
}
