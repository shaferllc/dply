<?php

declare(strict_types=1);

namespace App\Modules\Edge\Actions;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use RuntimeException;

/**
 * Configures (or clears) A/B split traffic for a production Edge
 * site. Stores the config on the parent's `meta.edge.split` and
 * re-publishes the host map so the Worker picks up the new rule
 * without waiting for a redeploy.
 *
 * One active split per site — running multiple concurrent
 * experiments would require per-variant cookie namespacing + UI to
 * pick variants by name. Out of scope for v1; the wizard already
 * encourages "promote the preview once you're confident" as the
 * end state.
 */
class UpdateEdgeSplitTraffic
{
    public function configure(
        Site $parent,
        string $previewSiteId,
        int $percentage,
        bool $sticky,
        string $cookieName = 'dply_variant',
    ): void {
        if (! $parent->usesEdgeRuntime() || $parent->isEdgePreview()) {
            throw new RuntimeException('Split traffic is configured on the production parent site.');
        }
        if ($percentage < 1 || $percentage > 99) {
            throw new RuntimeException('Split percentage must be between 1 and 99 — use Promote to send 100%.');
        }

        $preview = Site::query()->find($previewSiteId);
        if ($preview === null
            || $preview->organization_id !== $parent->organization_id
            || ($preview->edgeMeta()['preview_parent_site_id'] ?? null) !== $parent->id) {
            throw new RuntimeException('Preview not found or not a child of this site.');
        }

        $previewDeployment = EdgeDeployment::query()
            ->where('site_id', $preview->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('published_at')
            ->first();

        if ($previewDeployment === null || $previewDeployment->storage_prefix === null) {
            throw new RuntimeException('Preview has no live deployment with artifacts — redeploy the preview first.');
        }

        $cookieName = trim($cookieName);
        if ($cookieName === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $cookieName) !== 1) {
            throw new RuntimeException('Cookie name must be 1-64 chars and contain only letters / digits / underscores / dashes.');
        }

        $parent->mergeEdgeMeta([
            'split' => [
                'enabled' => true,
                'preview_site_id' => (string) $preview->id,
                'preview_deployment_id' => (string) $previewDeployment->id,
                'preview_storage_prefix' => $previewDeployment->storage_prefix,
                'percentage' => $percentage,
                'sticky_cookie' => $sticky ? $cookieName : null,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
        $parent->save();

        $this->republishParentActive($parent->fresh());
    }

    public function clear(Site $parent): void
    {
        if (! $parent->usesEdgeRuntime() || $parent->isEdgePreview()) {
            return;
        }

        $parent->mergeEdgeMeta(['split' => null]);
        $parent->save();

        $this->republishParentActive($parent->fresh());
    }

    private function republishParentActive(Site $parent): void
    {
        $activeId = $parent->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            return;
        }
        $deployment = EdgeDeployment::query()->find($activeId);
        if ($deployment === null || $deployment->status !== EdgeDeployment::STATUS_LIVE) {
            return;
        }
        app(EdgeHostMapPublisher::class)->publish($parent, $deployment);
    }
}
