<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Enums\SiteType;
use App\Jobs\ProvisionEdgeSiteJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Spawn a preview deployment for a source-mode edge site.
 *
 * The "parent" Site is the production source-mode deploy (created by
 * CreateEdgeSiteFromSource). A preview is a sibling Site that points
 * at the same repo but a different branch — typically a feature/PR
 * branch — and lives until the operator (or their CI) tears it down.
 *
 * Naming: previews get slugged names like `pr-123-parent-slug` when a
 * PR number is supplied, or `preview-{branch}-parent-slug` otherwise.
 * Backend, region, port, and env vars are copied from the parent so
 * the preview matches its production sibling.
 *
 * Idempotency: if a preview already exists for the same parent +
 * branch, the existing site is returned; no second site is spawned
 * (so a CI re-running on the same PR doesn't duplicate).
 */
class CreateEdgePreviewSite
{
    public function handle(
        Site $parent,
        string $branch,
        ?int $prNumber = null,
    ): Site {
        $parentSource = $parent->meta['container']['source'] ?? null;
        if (! is_array($parentSource) || ! is_string($parentSource['repo'] ?? null)) {
            throw new \RuntimeException(
                'Parent site has no source spec — only source-mode edge sites can spawn previews.',
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

        $server = Server::query()->create([
            'user_id' => $parent->user_id,
            'organization_id' => $parent->organization_id,
            'name' => 'edge-'.$slug,
            'status' => Server::STATUS_PENDING,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DPLY_EDGE,
                'edge' => [
                    'backend' => $parent->container_backend,
                    'region' => $parent->container_region,
                ],
            ],
        ]);

        $sourceSpec = [
            'repo' => (string) $parentSource['repo'],
            'branch' => $branch,
            // Auto-deploy stays on so the backend keeps the preview in
            // sync with new pushes to the PR branch — matches Vercel.
            'deploy_on_push' => true,
        ];
        if (! empty($parentSource['dockerfile_path']) && is_string($parentSource['dockerfile_path'])) {
            $sourceSpec['dockerfile_path'] = $parentSource['dockerfile_path'];
        }

        $site = Site::query()->create([
            'server_id' => $server->id,
            'user_id' => $parent->user_id,
            'organization_id' => $parent->organization_id,
            'name' => $name,
            'slug' => $slug,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => $parent->container_port,
            'container_backend' => $parent->container_backend,
            'container_region' => $parent->container_region,
            'env_file_content' => $parent->env_file_content,
            'status' => Site::STATUS_PENDING,
            'webhook_secret' => Str::random(48),
            'meta' => [
                'container' => [
                    'source' => $sourceSpec,
                    'preview_parent_site_id' => $parent->id,
                    'preview_branch' => $branch,
                    'preview_pr_number' => $prNumber,
                ],
            ],
        ]);

        ProvisionEdgeSiteJob::dispatch($site->id);

        return $site;
    }

    /**
     * Look up an existing (live, not-torn-down) preview for the
     * given parent + branch. Used both by handle() (idempotency)
     * and by the teardown CLI to find what to remove.
     *
     * Torn-down previews are excluded so re-running CI on a closed
     * + reopened PR spawns a fresh preview rather than returning
     * the dead row.
     */
    public static function findExisting(Site $parent, string $branch): ?Site
    {
        return self::livePreviewQuery($parent)
            ->whereJsonContains('meta->container->preview_branch', $branch)
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
            ->whereJsonContains('meta->container->preview_parent_site_id', $parent->id)
            ->whereNull('meta->container->torn_down_at');
    }

    private function previewSlug(string $parentSlug, string $branch, ?int $prNumber): string
    {
        $parentSlug = $parentSlug !== '' ? $parentSlug : 'app';
        if ($prNumber !== null && $prNumber > 0) {
            return Str::slug('pr-'.$prNumber.'-'.$parentSlug);
        }

        // Branches commonly contain "/" (e.g. feature/login-form). Str::slug
        // drops it without inserting a separator, which produces ugly
        // run-on slugs ("featurelogin-form"). Pre-replace separators.
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
