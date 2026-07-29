<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Resources;

use App\Models\EdgeSiteAccessRule;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeAccessGate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-API representation of an Edge site row.
 *
 * @property Site $resource
 */
final class EdgeSiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $site = $this->resource;
        $edge = $site->edgeMeta();
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];

        return [
            'id' => (string) $site->id,
            'organization_id' => (string) $site->organization_id,
            'name' => $site->name,
            'slug' => $site->slug,
            'status' => $site->status,
            'is_preview' => $site->isEdgePreview(),
            'parent_site_id' => is_string($edge['preview_parent_site_id'] ?? null)
                ? $edge['preview_parent_site_id']
                : null,
            'runtime_mode' => $edge['runtime_mode'] ?? 'static',
            'hostname' => $site->edgeHostname(),
            'live_url' => $site->edgeLiveUrl(),
            'dashboard_url' => route('sites.show', [
                'server' => $site->server_id,
                'site' => $site->id,
            ], absolute: true),
            'repository' => is_string($source['repo'] ?? null) ? $source['repo'] : null,
            'branch' => is_string($source['branch'] ?? null) ? $source['branch'] : null,
            'repo_root' => $site->edgeRepoRoot() !== '' ? $site->edgeRepoRoot() : null,
            'active_deployment_id' => is_string($edge['active_deployment_id'] ?? null)
                ? $edge['active_deployment_id']
                : null,
            'preview_protection' => $this->previewProtectionSummary($site),
            'created_at' => $site->created_at->toIso8601String(),
            'updated_at' => $site->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array{mode: string, enabled: bool, inherited_from_site_id?: string}
     */
    private function previewProtectionSummary(Site $site): array
    {
        if ($site->isEdgePreview()) {
            $parentId = $site->edgeMeta()['preview_parent_site_id'] ?? null;
            if (is_string($parentId) && $parentId !== '') {
                $parent = Site::query()->find($parentId);
                if ($parent !== null) {
                    $rule = app(EdgeAccessGate::class)->ruleForSite($parent);

                    return [
                        'mode' => is_string($rule?->mode) ? $rule->mode : EdgeSiteAccessRule::MODE_OFF,
                        'enabled' => $rule !== null && $rule->isEnabled(),
                        'inherited_from_site_id' => (string) $parent->id,
                    ];
                }
            }
        }

        $rule = $site->edgeSiteAccessRule;

        return [
            'mode' => is_string($rule?->mode) ? $rule->mode : EdgeSiteAccessRule::MODE_OFF,
            'enabled' => $rule !== null && $rule->isEnabled(),
        ];
    }
}
