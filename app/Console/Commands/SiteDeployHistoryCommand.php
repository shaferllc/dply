<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeployStep;
use Illuminate\Console\Command;

/**
 * List recent deployments for a site with per-phase breakdown.
 *
 *   dply:site:deploy-history <site> [--limit=10] [--json]
 *
 * Same data the "Recent deployments" dashboard panel renders, exposed
 * at the CLI for ops review and CI scripting. Each row shows status,
 * trigger, started_at, total duration, and a one-line summary of
 * which phases ran.
 */
class SiteDeployHistoryCommand extends Command
{
    protected $signature = 'dply:site:deploy-history
        {site : Site ID, slug, or name}
        {--limit=10 : Number of recent deployments to show}
        {--json : Output the history as JSON}';

    protected $description = 'List recent deployments for a site with per-phase status, timing, and trigger.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $limit = max(1, min(100, (int) ($this->option('limit') ?? 10)));
        $deployments = $site->deployments()
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'count' => $deployments->count(),
                'deployments' => $deployments->map(fn (SiteDeployment $d) => [
                    'id' => $d->id,
                    'status' => $d->status,
                    'trigger' => $d->trigger,
                    'started_at' => $d->started_at?->toAtomString(),
                    'finished_at' => $d->finished_at?->toAtomString(),
                    'total_duration_ms' => $d->phaseTotalDurationMs(),
                    'phases' => array_filter([
                        SiteDeployStep::PHASE_BUILD => $d->hasPhase(SiteDeployStep::PHASE_BUILD)
                            ? ['ok' => $d->phaseOk(SiteDeployStep::PHASE_BUILD), 'steps' => count($d->phaseSteps(SiteDeployStep::PHASE_BUILD))]
                            : null,
                        SiteDeployStep::PHASE_SWAP => $d->hasPhase(SiteDeployStep::PHASE_SWAP)
                            ? ['ok' => $d->phaseOk(SiteDeployStep::PHASE_SWAP), 'steps' => count($d->phaseSteps(SiteDeployStep::PHASE_SWAP))]
                            : null,
                        SiteDeployStep::PHASE_RELEASE => $d->hasPhase(SiteDeployStep::PHASE_RELEASE)
                            ? ['ok' => $d->phaseOk(SiteDeployStep::PHASE_RELEASE), 'steps' => count($d->phaseSteps(SiteDeployStep::PHASE_RELEASE))]
                            : null,
                        SiteDeployStep::PHASE_RESTART => $d->hasPhase(SiteDeployStep::PHASE_RESTART)
                            ? ['ok' => $d->phaseOk(SiteDeployStep::PHASE_RESTART), 'steps' => count($d->phaseSteps(SiteDeployStep::PHASE_RESTART))]
                            : null,
                    ], fn ($v) => $v !== null),
                ])->all(),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($deployments->isEmpty()) {
            $this->info('No deployments recorded for '.$site->name.'.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("<fg=cyan>Recent deployments for</> <fg=white;options=bold>{$site->name}</>");
        $this->newLine();

        $rows = [];
        foreach ($deployments as $deployment) {
            $statusColor = match ($deployment->status) {
                SiteDeployment::STATUS_SUCCESS => 'green',
                SiteDeployment::STATUS_FAILED => 'red',
                default => 'yellow',
            };
            $rows[] = [
                substr((string) $deployment->id, -8),
                "<fg={$statusColor}>{$deployment->status}</>",
                (string) ($deployment->trigger ?? '—'),
                $deployment->started_at?->diffForHumans() ?? '—',
                $this->formatDuration($deployment->phaseTotalDurationMs()),
                $this->summarizePhases($deployment),
            ];
        }

        $this->table(['id', 'status', 'trigger', 'when', 'duration', 'phases'], $rows);

        return self::SUCCESS;
    }

    private function summarizePhases(SiteDeployment $deployment): string
    {
        $parts = [];
        foreach ([SiteDeployStep::PHASE_BUILD, SiteDeployStep::PHASE_SWAP, SiteDeployStep::PHASE_RELEASE, SiteDeployStep::PHASE_RESTART] as $phase) {
            if (! $deployment->hasPhase($phase)) {
                continue;
            }
            $glyph = $deployment->phaseOk($phase) ? '✓' : '✗';
            $count = count($deployment->phaseSteps($phase));
            $parts[] = "{$phase}({$count}){$glyph}";
        }

        return $parts === [] ? '—' : implode(' ', $parts);
    }

    private function formatDuration(int $ms): string
    {
        if ($ms === 0) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms.'ms';
        }

        return number_format($ms / 1000, 1).'s';
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
