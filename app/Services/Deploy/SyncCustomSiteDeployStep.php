<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;
use App\Models\SiteDeployStep;

/**
 * Mirrors a Custom site's attached deploy Script into a single
 * Release-phase SiteDeployStep so the existing DeploymentRunner picks
 * it up. Idempotent — safe to call on creation and whenever the Script
 * content changes.
 */
class SyncCustomSiteDeployStep
{
    public const STEP_NAME = 'custom_site_deploy';

    public function sync(Site $site): ?SiteDeployStep
    {
        if (! $site->isCustom()) {
            return null;
        }

        $script = $site->deployScript;
        $content = $script ? trim((string) $script->content) : '';

        $site->deploySteps()
            ->where('phase', SiteDeployStep::PHASE_RELEASE)
            ->where('step_type', SiteDeployStep::TYPE_CUSTOM)
            ->where('sort_order', 0)
            ->delete();

        if ($content === '') {
            return null;
        }

        return $site->deploySteps()->create([
            'sort_order' => 0,
            'step_type' => SiteDeployStep::TYPE_CUSTOM,
            'phase' => SiteDeployStep::PHASE_RELEASE,
            'custom_command' => $content,
            'timeout_seconds' => 600,
        ]);
    }
}
