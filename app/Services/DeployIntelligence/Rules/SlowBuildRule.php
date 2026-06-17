<?php

declare(strict_types=1);

namespace App\Services\DeployIntelligence\Rules;

use App\Models\DeployIntelligenceAlert;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\DeployIntelligence\AlertFinding;
use App\Services\DeployIntelligence\Contracts\IntelligenceRule;

/**
 * Flags a site whose most recent successful deploy ran materially
 * slower than its own p50 over the last 20 successful deploys. The
 * threshold (40% slower) is the differentiation-doc spec; we ignore
 * sites with fewer than 5 baseline deploys since the median isn't
 * meaningful yet.
 */
class SlowBuildRule implements IntelligenceRule
{
    public const SLOWDOWN_THRESHOLD = 1.4;

    public const BASELINE_WINDOW = 20;

    public const MIN_BASELINE_SAMPLES = 5;

    public function key(): string
    {
        return DeployIntelligenceAlert::RULE_SLOW_BUILD;
    }

    /** @return array<string, mixed> */
    /**
     * @return list<App\Services\DeployIntelligence\AlertFinding>
     */
    public function evaluate(Organization $organization): array
    {
        $serverIds = Server::query()
            ->where('organization_id', $organization->id)
            ->pluck('id');

        $sites = Site::query()
            ->where(function ($q) use ($organization, $serverIds): void {
                $q->where('organization_id', $organization->id)
                    ->orWhereIn('server_id', $serverIds);
            })
            ->get(['id', 'name', 'server_id', 'organization_id']);

        $findings = [];
        foreach ($sites as $site) {
            $finding = $this->evaluateSite($site);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function evaluateSite(Site $site): ?AlertFinding
    {
        $recent = SiteDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', SiteDeployment::STATUS_SUCCESS)
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->limit(self::BASELINE_WINDOW + 1)
            ->get(['id', 'started_at', 'finished_at']);

        if ($recent->count() < self::MIN_BASELINE_SAMPLES + 1) {
            return null;
        }

        $latest = $recent->shift();
        $latestDuration = $latest->finished_at->getTimestamp() - $latest->started_at->getTimestamp();
        if ($latestDuration <= 0) {
            return null;
        }

        $baselineDurations = $recent
            ->map(fn (SiteDeployment $d) => $d->finished_at->getTimestamp() - $d->started_at->getTimestamp())
            ->filter(fn (int $d) => $d > 0)
            ->sort()
            ->values()
            ->all();

        if (count($baselineDurations) < self::MIN_BASELINE_SAMPLES) {
            return null;
        }

        $median = $this->median($baselineDurations);
        if ($median <= 0) {
            return null;
        }

        $ratio = $latestDuration / $median;
        if ($ratio < self::SLOWDOWN_THRESHOLD) {
            return null;
        }

        $pctSlower = (int) round(($ratio - 1.0) * 100);

        return new AlertFinding(
            ruleKey: $this->key(),
            severity: $ratio >= 2.0 ? DeployIntelligenceAlert::SEVERITY_DANGER : DeployIntelligenceAlert::SEVERITY_WARNING,
            signature: 'site:'.$site->id.':'.$latest->id,
            title: __('Deploy :pct% slower than usual', ['pct' => $pctSlower]),
            summary: __(':site took :latest, normally :baseline.', [
                'site' => $site->name,
                'latest' => $this->humanDuration($latestDuration),
                'baseline' => $this->humanDuration((int) $median),
            ]),
            subject: $site,
            payload: [
                'site' => $site->name,
                'site_id' => (string) $site->id,
                'latest_deployment_id' => (string) $latest->id,
                'latest_duration_seconds' => $latestDuration,
                'baseline_median_seconds' => (int) $median,
                'baseline_sample_size' => count($baselineDurations),
                'slowdown_ratio' => round($ratio, 2),
            ],
        );
    }

    /**
     * @param  array<string, mixed> $sortedAsc
     */
    private function median(array $sortedAsc): float
    {
        $n = count($sortedAsc);
        if ($n === 0) {
            return 0.0;
        }
        $mid = (int) ($n / 2);
        if ($n % 2 === 1) {
            return (float) $sortedAsc[$mid];
        }

        return ($sortedAsc[$mid - 1] + $sortedAsc[$mid]) / 2.0;
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }
        $minutes = (int) floor($seconds / 60);
        $remaining = $seconds % 60;

        return $remaining > 0 ? $minutes.'m '.$remaining.'s' : $minutes.'m';
    }
}
