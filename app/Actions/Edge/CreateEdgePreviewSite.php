<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Enums\SiteType;
use App\Jobs\BuildEdgeSiteJob;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Support\Edge\EdgeTestingDomains;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Spawn a preview deployment for a source-mode Edge site.
 *
 * Idempotent on (parent, branch) — re-running CI on the same PR returns
 * the existing preview row instead of duplicating.
 */
class CreateEdgePreviewSite
{
    public function handle(
        Site $parent,
        string $branch,
        ?int $prNumber = null,
    ): Site {
        $parentSource = $parent->edgeMeta()['source'] ?? null;
        if (! is_array($parentSource) || ! is_string($parentSource['repo'] ?? null)) {
            throw new \RuntimeException(
                'Parent site has no source spec — only git-connected Edge sites can spawn previews.',
            );
        }

        $branch = trim($branch);
        if ($branch === '') {
            throw new \InvalidArgumentException('Branch is required to create a preview deployment.');
        }

        $existing = self::findExisting($parent, $branch);
        if ($existing !== null) {
            return $existing;
        }

        $slug = $this->previewSlug($parent->slug ?? Str::slug($parent->name), $branch, $prNumber);
        $name = $this->previewName($parent->name, $branch, $prNumber);
        $testingDomain = EdgeTestingDomains::defaultApex();
        $hostname = $slug.'.'.$testingDomain;

        $parentBuild = is_array($parent->edgeMeta()['build'] ?? null) ? $parent->edgeMeta()['build'] : [];
        $parentRouting = is_array($parent->edgeMeta()['routing'] ?? null) ? $parent->edgeMeta()['routing'] : [];

        $server = Server::query()->create([
            'user_id' => $parent->user_id,
            'organization_id' => $parent->organization_id,
            'name' => 'edge-'.$slug,
            'status' => Server::STATUS_READY,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DPLY_EDGE,
            ],
        ]);

        $sourceSpec = [
            'repo' => (string) $parentSource['repo'],
            'branch' => $branch,
            'deploy_on_push' => true,
        ];

        $site = Site::query()->create([
            'server_id' => $server->id,
            'user_id' => $parent->user_id,
            'organization_id' => $parent->organization_id,
            'name' => $name,
            'slug' => $slug,
            'type' => SiteType::Static,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'edge_backend' => $parent->edge_backend,
            'status' => Site::STATUS_EDGE_PROVISIONING,
            'webhook_secret' => Str::random(48),
            'meta' => [
                'runtime_profile' => 'edge_web',
                'edge' => [
                    'runtime_mode' => $parent->edgeMeta()['runtime_mode'] ?? 'static',
                    'source' => $sourceSpec,
                    'build' => $parentBuild,
                    'routing' => array_merge($parentRouting, [
                        'hostname' => $hostname,
                    ]),
                    'live_url' => 'https://'.$hostname,
                    'preview_parent_site_id' => $parent->id,
                    'preview_branch' => $branch,
                    'preview_pr_number' => $prNumber,
                ],
            ],
        ]);

        $prefix = trim((string) config('edge.r2.key_prefix', 'edge/'), '/')
            .'/'.$parent->organization_id.'/'.$site->id.'/'.Str::ulid();

        $deployment = EdgeDeployment::query()->create([
            'site_id' => $site->id,
            'organization_id' => $parent->organization_id,
            'status' => EdgeDeployment::STATUS_BUILDING,
            'git_branch' => $branch,
            'storage_prefix' => $prefix,
        ]);

        BuildEdgeSiteJob::dispatch($deployment->id);

        return $site;
    }

    public static function findExisting(Site $parent, string $branch): ?Site
    {
        return self::livePreviewQuery($parent)
            ->whereJsonContains('meta->edge->preview_branch', $branch)
            ->first();
    }

    /**
     * @return Collection<int, Site>
     */
    public static function listForParent(Site $parent): Collection
    {
        return self::livePreviewQuery($parent)
            ->orderByDesc('created_at')
            ->get();
    }

    private static function livePreviewQuery(Site $parent): Builder
    {
        return Site::query()
            ->where('organization_id', $parent->organization_id)
            ->whereJsonContains('meta->edge->preview_parent_site_id', $parent->id)
            ->whereNull('meta->edge->torn_down_at');
    }

    private function previewSlug(string $parentSlug, string $branch, ?int $prNumber): string
    {
        $parentSlug = $parentSlug !== '' ? $parentSlug : 'app';
        if ($prNumber !== null && $prNumber > 0) {
            return Str::slug('pr-'.$prNumber.'-'.$parentSlug);
        }

        $branchSlug = Str::slug(str_replace(['/', '_'], '-', $branch));
        if ($branchSlug === '') {
            $branchSlug = substr(md5($branch), 0, 8);
        }

        return Str::slug('preview-'.$branchSlug.'-'.$parentSlug);
    }

    private function previewName(string $parentName, string $branch, ?int $prNumber): string
    {
        if ($prNumber !== null && $prNumber > 0) {
            return sprintf('PR #%d — %s', $prNumber, $parentName);
        }

        return sprintf('%s preview (%s)', $parentName, $branch);
    }
}
