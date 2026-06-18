<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\Site;
use App\Services\SshConnection;
use Illuminate\Support\Collection;

class ServerCronSynchronizer
{
    /**
     * Composed crontab body from the most recent sync() call. Captured so the
     * Livewire workspace can render the exact rendered file with line numbers
     * when the host rejects it — the host's "bad minute" error references a
     * line in this body, not in our DB.
     */
    private ?string $lastBody = null;

    /** Offending line number parsed out of the host's `/tmp/...:N:` error. */
    private ?int $lastBadLine = null;

    /** Content of that line, sliced out of {@see $lastBody}. */
    private ?string $lastBadLineContent = null;

    public function lastBody(): ?string
    {
        return $this->lastBody;
    }

    public function lastBadLine(): ?int
    {
        return $this->lastBadLine;
    }

    public function lastBadLineContent(): ?string
    {
        return $this->lastBadLineContent;
    }

    /**
     * Pre-flight: returns rows whose cron_expression would be rejected by
     * `crontab` (e.g. "bad minute"). Callers should run this BEFORE sync()
     * and surface the list with edit affordances — pushing a bad expression
     * over SSH means the whole DPLY MANAGED block is rejected and the
     * remote crontab keeps its prior state (including any stale lines).
     *
     * Whitespace inside expressions is collapsed before validation so a
     * trailing space or double-tap doesn't trip an otherwise valid line.
     *
     * @param  Collection<int, ServerCronJob>  $jobs
     * @return list<array{id: string, description: string, command: string, cron_expression: string}>
     */
    public function invalidExpressions(Collection $jobs): array
    {
        $validator = app(CronExpressionValidator::class);
        $invalid = [];

        foreach ($jobs as $job) {
            if ($job->system_managed || ! $job->enabled) {
                continue;
            }

            if (! $validator->isValid($this->normalizeExpression((string) $job->cron_expression))) {
                $invalid[] = [
                    'id' => (string) $job->id,
                    'description' => (string) ($job->description ?? ''),
                    'command' => (string) $job->command,
                    'cron_expression' => (string) $job->cron_expression,
                ];
            }
        }

        return $invalid;
    }

    private function normalizeExpression(string $expression): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $expression));
    }

    public function sync(Server $server, ?Collection $onlyJobs = null): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        // System-managed rows mirror crontab lines that live in their
        // own dply-owned block (currently the metrics push agent's
        // `BEGIN DPLY METRICS GUEST` block). They surface in the
        // workspace list for visibility but must NOT enter the standard
        // managed block — that block has its own deploy lifecycle.
        $jobs = ($onlyJobs ?? $server->cronJobs)->reject(fn (ServerCronJob $job): bool => (bool) $job->system_managed)->values();
        if ($jobs->isEmpty()) {
            return 'No cron jobs to sync.';
        }

        $markerBegin = '# BEGIN DPLY MANAGED';
        $markerEnd = '# END DPLY MANAGED';
        $schedBegin = '# BEGIN DPLY LARAVEL SCHEDULER';
        $schedEnd = '# END DPLY LARAVEL SCHEDULER';

        $current = $this->readCurrentCrontab($server);
        $before = $this->stripManagedBlock($current, $markerBegin, $markerEnd);
        $before = $this->stripManagedBlock($before, $schedBegin, $schedEnd);
        $before = rtrim($before)."\n\n";

        $builder = app(ServerCronCommandBuilder::class);

        $server->loadMissing('organization');
        $maintenanceUntil = $server->organization?->cron_maintenance_until;

        $block = $markerBegin."\n";
        if ($maintenanceUntil !== null && now()->lt($maintenanceUntil)) {
            $note = trim((string) $server->organization->cron_maintenance_note);
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
                // Belt-and-braces: every crontab entry must fit on a single line.
                // ServerCronCommandBuilder already flattens multi-line inputs, but
                // if anything regresses we'd silently produce a "bad minute" error
                // because the second half of the entry has no schedule fields.
                $segment = (string) preg_replace('/\r?\n+/', '; ', $segment);
                $expression = (string) preg_replace('/\s+/', ' ', trim($job->cron_expression));
                $block .= $expression.' '.$segment."\n";
            }
        }
        $block .= $markerEnd."\n";

        $schedBlock = $this->buildLaravelSchedulerBlock($server);

        $newCrontab = $before.$block.$schedBlock;
        $this->lastBody = $newCrontab;
        $this->lastBadLine = null;
        $this->lastBadLineContent = null;

        $out = $this->writeCrontab($server, $newCrontab);
        $ok = (bool) preg_match('/DPLY_CRON_EXIT:0\s*$/', $out);

        if (! $ok) {
            // crontab(1) emits `"/tmp/dply_crontab_xxx":N: bad <field>` when it
            // rejects a line. Pull N out and grab that line from the body so the
            // workspace can show the operator exactly what the host rejected —
            // our PHP validator (dragonmantank/cron-expression) is more lenient
            // than vixie/ISC cron, so passing pre-flight doesn't guarantee the
            // host will accept it.
            if (preg_match('/:(?<line>\d+):\s*bad\s+\w+/i', $out, $m)) {
                $lineNumber = (int) $m['line'];
                $lines = preg_split("/\r?\n/", $newCrontab) ?: [];
                $this->lastBadLine = $lineNumber;
                $this->lastBadLineContent = $lines[$lineNumber - 1] ?? null;
            }
        }

        foreach ($jobs as $job) {
            $job->update([
                'is_synced' => $ok,
                // Snapshot the `enabled` value the host now reflects. Toggle
                // round-trips read this back to decide if `is_synced` should
                // flip clean again — see WorkspaceCron::toggleCronJob.
                'last_synced_enabled' => $ok ? (bool) $job->enabled : $job->last_synced_enabled,
                'last_sync_error' => $ok ? null : $out,
            ]);
        }

        return $out;
    }

    protected function readCurrentCrontab(Server $server): string
    {
        $lastError = null;

        foreach ($this->sshLoginCandidates($server) as $loginUser) {
            try {
                $ssh = $this->makeConnection($server, $loginUser);
                $command = $loginUser === 'root' && trim((string) $server->ssh_user) !== 'root'
                    ? 'crontab -u '.escapeshellarg((string) $server->ssh_user).' -l 2>/dev/null || true'
                    : 'crontab -l 2>/dev/null || true';
                $output = $ssh->exec($command, 30);
                $ssh->disconnect();

                return $output;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new \RuntimeException('SSH connection failed for all cron login candidates.');
    }

    protected function writeCrontab(Server $server, string $newCrontab): string
    {
        $lastError = null;
        $sshUser = trim((string) $server->ssh_user) ?: 'root';

        foreach ($this->sshLoginCandidates($server) as $loginUser) {
            $tmp = '/tmp/dply_crontab_'.bin2hex(random_bytes(6));

            try {
                $ssh = $this->makeConnection($server, $loginUser);
                $ssh->putFile($tmp, $newCrontab);
                $installCommand = $loginUser === 'root' && $sshUser !== 'root'
                    ? 'crontab -u '.escapeshellarg($sshUser).' '.escapeshellarg($tmp).' 2>&1; ec=$?; rm -f '.escapeshellarg($tmp).'; echo DPLY_CRON_EXIT:$ec'
                    : 'crontab '.escapeshellarg($tmp).' 2>&1; ec=$?; rm -f '.escapeshellarg($tmp).'; echo DPLY_CRON_EXIT:$ec';
                $output = $ssh->exec($installCommand, 60);
                $ssh->disconnect();

                return $output;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new \RuntimeException('SSH connection failed for all cron login candidates.');
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
            ->get()
            // Honour the same applicability rule as the UI: a stale
            // `laravel_scheduler = true` on a now-confidently-non-Laravel site
            // (reclassified to WordPress/Symfony, or no longer PHP) must NOT keep
            // installing the per-minute cron. The stored flag is left untouched
            // so the choice returns if the stack flips back to Laravel.
            ->filter(fn (Site $site): bool => $site->supportsLaravelScheduler())
            ->values();

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

    protected function makeConnection(Server $server, string $loginUser): SshConnection
    {
        $role = $loginUser === 'root'
            ? SshConnection::ROLE_RECOVERY
            : SshConnection::ROLE_OPERATIONAL;

        return new SshConnection($server, $loginUser, $role);
    }
}
