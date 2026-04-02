<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;

final class DeploymentRevisionTracker
{
    public function appliedRevision(Site $site, string $category = 'runtime'): ?string
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $revision = data_get($meta, 'deployment_foundation.applied_revisions.'.$category);

        return is_string($revision) && $revision !== '' ? $revision : null;
    }

    public function markApplied(Site $site, string $revision, string $category = 'runtime'): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        data_set($meta, 'deployment_foundation.applied_revisions.'.$category, $revision);
        data_set($meta, 'deployment_foundation.applied_at.'.$category, now()->toIso8601String());

        $site->forceFill(['meta' => $meta])->save();
        $site->setAttribute('meta', $meta);
    }
}
