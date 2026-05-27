<?php

declare(strict_types=1);

namespace App\Services\DeployIntelligence\Rules;

use App\Models\DeployIntelligenceAlert;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Services\DeployIntelligence\AlertFinding;
use App\Services\DeployIntelligence\Contracts\IntelligenceRule;

/**
 * Flags Edge sites where the preview scope and production scope have
 * diverged in *key set*. We deliberately compare keys only, not values
 * — values often differ legitimately (test keys, staging URLs) but
 * a missing key in one environment is almost always a deploy-time
 * surprise waiting to happen.
 *
 * Differentiation-doc spec: "prod env missing key from preview".
 */
class EnvDriftRule implements IntelligenceRule
{
    public function key(): string
    {
        return DeployIntelligenceAlert::RULE_ENV_DRIFT;
    }

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
            ->get(['id', 'name', 'server_id', 'organization_id', 'edge_backend']);

        $edgeSites = $sites->filter(fn (Site $s) => $s->usesEdgeRuntime());
        if ($edgeSites->isEmpty()) {
            return [];
        }

        $varsBySite = EdgeSiteEnvVar::query()
            ->whereIn('site_id', $edgeSites->pluck('id'))
            ->get(['site_id', 'key', 'scope'])
            ->groupBy('site_id');

        $findings = [];
        foreach ($edgeSites as $site) {
            $finding = $this->evaluateSite($site, $varsBySite->get($site->id, collect()));
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, EdgeSiteEnvVar>  $vars
     */
    private function evaluateSite(Site $site, $vars): ?AlertFinding
    {
        if ($vars->isEmpty()) {
            return null;
        }

        $byScope = $vars->groupBy('scope');
        $prodKeys = $byScope->get(EdgeSiteEnvVar::SCOPE_PRODUCTION, collect())
            ->pluck('key')
            ->unique()
            ->values()
            ->all();
        $previewKeys = $byScope->get(EdgeSiteEnvVar::SCOPE_PREVIEW, collect())
            ->pluck('key')
            ->unique()
            ->values()
            ->all();

        if ($prodKeys === [] || $previewKeys === []) {
            return null;
        }

        $inPreviewOnly = array_values(array_diff($previewKeys, $prodKeys));
        $inProdOnly = array_values(array_diff($prodKeys, $previewKeys));

        if ($inPreviewOnly === [] && $inProdOnly === []) {
            return null;
        }

        $summaryParts = [];
        if ($inPreviewOnly !== []) {
            $summaryParts[] = trans_choice(
                '{1} 1 key in preview missing from production|[2,*] :count keys in preview missing from production',
                count($inPreviewOnly),
                ['count' => count($inPreviewOnly)],
            );
        }
        if ($inProdOnly !== []) {
            $summaryParts[] = trans_choice(
                '{1} 1 key in production missing from preview|[2,*] :count keys in production missing from preview',
                count($inProdOnly),
                ['count' => count($inProdOnly)],
            );
        }

        sort($inPreviewOnly);
        sort($inProdOnly);

        return new AlertFinding(
            ruleKey: $this->key(),
            severity: DeployIntelligenceAlert::SEVERITY_WARNING,
            // Signature based on the symmetric difference so the alert
            // updates when keys change but stays the same row across
            // scans of the unchanged condition.
            signature: 'site:'.$site->id.':'.hash('sha1', json_encode([$inPreviewOnly, $inProdOnly])),
            title: __('Preview / production env drift on :site', ['site' => $site->name]),
            summary: implode(' · ', $summaryParts),
            subject: $site,
            payload: [
                'site' => $site->name,
                'site_id' => (string) $site->id,
                'preview_only_keys' => $inPreviewOnly,
                'production_only_keys' => $inProdOnly,
            ],
        );
    }
}
