<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Console\Command;

/**
 * Mark a stuck deploy as failed so the next deploy can proceed.
 *
 *   dply:site:abort-deploy <site> [--id=<deployment-id>]
 *                                 [--older-than=15] [--force]
 *
 * Without --id, targets the most recent deployment whose status is
 * still `running`. --older-than=N requires the running deploy to
 * have started at least N minutes ago — guards against killing a
 * fresh deploy by accident. Default is 15 minutes.
 *
 * Pure DB mutation: updates status=failed, finished_at=now. Does
 * NOT touch the remote build/release state on the server. Operator
 * is expected to inspect the server before re-deploying.
 *
 * --force overrides the --older-than guard for emergency use.
 */
class AbortSiteDeployCommand extends Command
{
    protected $signature = 'dply:site:abort-deploy
        {site : Site ID, slug, or name}
        {--id= : Specific deployment ID to abort (otherwise: latest running)}
        {--older-than=15 : Minimum age in minutes the deploy must be (default: 15)}
        {--force : Skip the --older-than guard}
        {--json : Output as JSON}';

    protected $description = 'Mark a stuck deployment as failed (DB only — does not touch the server).';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $id = $this->option('id');
        $deployment = $id !== null
            ? SiteDeployment::query()->where('site_id', $site->id)->where('id', $id)->first()
            : SiteDeployment::query()
                ->where('site_id', $site->id)
                ->where('status', SiteDeployment::STATUS_RUNNING)
                ->latest('started_at')
                ->first();

        if ($deployment === null) {
            $this->error($id !== null
                ? "Deployment not found: {$id}"
                : 'No running deployments to abort.');

            return self::FAILURE;
        }

        if ($deployment->status !== SiteDeployment::STATUS_RUNNING) {
            $this->error(sprintf(
                'Deployment %s is in status "%s", not "running".',
                $deployment->id,
                $deployment->status,
            ));

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $minMinutes = (int) ($this->option('older-than') ?? 15);
        if (! $force && $deployment->started_at !== null) {
            $ageMinutes = $deployment->started_at->diffInMinutes(now());
            if ($ageMinutes < $minMinutes) {
                $this->error(sprintf(
                    'Deployment %s is only %d minute(s) old (--older-than=%d, use --force to override).',
                    $deployment->id,
                    $ageMinutes,
                    $minMinutes,
                ));

                return self::FAILURE;
            }
        }

        $now = now();
        $deployment->status = SiteDeployment::STATUS_FAILED;
        $deployment->finished_at = $now;
        $deployment->save();

        $payload = [
            'site_id' => $site->id,
            'deployment_id' => $deployment->id,
            'previous_status' => SiteDeployment::STATUS_RUNNING,
            'new_status' => SiteDeployment::STATUS_FAILED,
            'aborted_at' => $now->toIso8601String(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Marked deployment %s as failed (was running for %s).',
            $deployment->id,
            $deployment->started_at?->diffForHumans() ?? '—',
        ));
        $this->line('<fg=yellow>Note: this only updates the DB row. Inspect the server before redeploying.</>');

        return self::SUCCESS;
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
