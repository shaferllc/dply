<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Edge;

use App\Http\Controllers\Controller;
use App\Models\EdgeDeployment;
use App\Models\EdgeSiteAccessRule;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Edge\EdgeAccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared lookup + payload helpers for the public /api/v1/edge/* surface.
 * Every Edge API controller extends this so org scoping, "not found"
 * shaping, and the Edge-specific 404 ("site exists but is not an Edge
 * site") are consistent.
 */
abstract class EdgeApiController extends Controller
{
    protected function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('api_organization');
        if (! $organization instanceof Organization) {
            abort(401);
        }

        return $organization;
    }

    /**
     * Look up an Edge site by ID within the request's organization.
     * Returns null when the site does not exist, belongs to another
     * org, or is not an Edge site — callers turn that into a 404.
     */
    protected function findEdgeSite(Request $request, string $siteId): ?Site
    {
        $organization = $this->organization($request);

        $site = Site::query()
            ->where('organization_id', $organization->id)
            ->find($siteId);

        if ($site === null || ! $site->usesEdgeRuntime()) {
            return null;
        }

        return $site;
    }

    protected function notFound(string $message = 'Edge site not found.'): JsonResponse
    {
        return response()->json(['message' => $message], 404);
    }

    /**
     * @return array<string, mixed>
     */
    protected function siteResource(Site $site): array
    {
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
            'created_at' => $site->created_at?->toIso8601String(),
            'updated_at' => $site->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deploymentResource(EdgeDeployment $deployment): array
    {
        $repoConfig = is_array($deployment->repo_config) ? $deployment->repo_config : null;

        return [
            'id' => (string) $deployment->id,
            'site_id' => (string) $deployment->site_id,
            'status' => $deployment->status,
            'git_commit' => $deployment->git_commit,
            'git_branch' => $deployment->git_branch,
            'storage_prefix' => $deployment->storage_prefix,
            'pruned' => $deployment->pruned_at !== null,
            'cf_kv_version' => $deployment->cf_kv_version,
            'aliases' => $deployment->aliasHostnames(),
            'repo_config' => $repoConfig === null ? null : [
                'source_path' => $repoConfig['source_path'] ?? null,
                'build_overrides' => $repoConfig['build'] ?? [],
                'redirect_count' => count((array) ($repoConfig['redirects'] ?? [])),
                'rewrite_count' => count((array) ($repoConfig['rewrites'] ?? [])),
                'header_rule_count' => count((array) ($repoConfig['headers'] ?? [])),
                'warning_count' => count((array) ($repoConfig['warnings'] ?? [])),
            ],
            'failure_reason' => $deployment->failure_reason,
            'published_at' => $deployment->published_at?->toIso8601String(),
            'failed_at' => $deployment->failed_at?->toIso8601String(),
            'created_at' => $deployment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function accessRuleResource(Site $site, ?EdgeSiteAccessRule $rule): array
    {
        $mode = is_string($rule?->mode) ? $rule->mode : EdgeSiteAccessRule::MODE_OFF;
        $enabled = $rule !== null && $rule->isEnabled();
        $appUrl = rtrim((string) config('app.url'), '/');

        $payload = [
            'site_id' => (string) $site->id,
            'mode' => $mode,
            'enabled' => $enabled,
            'password_set' => $mode === EdgeSiteAccessRule::MODE_PASSWORD
                && is_string($rule?->password_verifier) && $rule->password_verifier !== '',
            'allowed_emails' => $rule?->normalizedAllowedEmails() ?? [],
        ];

        if ($enabled && $mode === EdgeSiteAccessRule::MODE_DPLY_ACCOUNT && $appUrl !== '') {
            $payload['account_login_url'] = $appUrl.'/edge/sites/'.$site->id.'/preview-access';
        }

        return $payload;
    }

    /**
     * @return array{mode: string, enabled: bool}
     */
    protected function previewProtectionSummary(Site $site): array
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
