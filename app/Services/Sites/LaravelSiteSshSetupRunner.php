<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDeployStep;
use InvalidArgumentException;

/**
 * One-shot Laravel maintenance commands over SSH from the site UI (BYO VM hosts).
 *
 * Action keys match {@see SiteDeployStep::step_type} so operators see the same commands
 * as the ordered deploy pipeline.
 */
class LaravelSiteSshSetupRunner
{
    public const ACTION_COMPOSER_INSTALL = SiteDeployStep::TYPE_COMPOSER_INSTALL;

    public const ACTION_ARTISAN_OPTIMIZE = SiteDeployStep::TYPE_ARTISAN_OPTIMIZE;

    public const ACTION_OCTANE_INSTALL = SiteDeployStep::TYPE_ARTISAN_OCTANE_INSTALL;

    public const ACTION_REVERB_INSTALL = SiteDeployStep::TYPE_ARTISAN_REVERB_INSTALL;

    /**
     * @return list<string>
     */
    public function allowedActions(Site $site): array
    {
        if (! $site->canRunLaravelSshSetupActions()) {
            return [];
        }

        $actions = [
            self::ACTION_COMPOSER_INSTALL,
            self::ACTION_ARTISAN_OPTIMIZE,
        ];
        if ($site->resolvedLaravelPackageFlag('octane')) {
            $actions[] = self::ACTION_OCTANE_INSTALL;
        }
        if ($site->resolvedLaravelPackageFlag('reverb')) {
            $actions[] = self::ACTION_REVERB_INSTALL;
        }

        return $actions;
    }

    public function assertActionAllowed(Site $site, string $action): void
    {
        if (! in_array($action, $this->allowedActions($site), true)) {
            throw new InvalidArgumentException(__('This action is not available for this site.'));
        }
    }

    public function commandFor(Site $site, string $action): string
    {
        $this->assertActionAllowed($site, $action);

        $fragment = SiteDeployPipelineCommands::fragmentFor($action, '');
        if ($fragment === null || $fragment === '') {
            throw new InvalidArgumentException(__('Unknown Laravel setup action.'));
        }

        $dir = escapeshellarg($site->effectiveEnvDirectory());

        return "cd {$dir} && {$fragment}";
    }

    public function timeoutSecondsFor(string $action): int
    {
        return match ($action) {
            self::ACTION_COMPOSER_INSTALL => 900,
            self::ACTION_OCTANE_INSTALL, self::ACTION_REVERB_INSTALL => 600,
            self::ACTION_ARTISAN_OPTIMIZE => 300,
            default => 300,
        };
    }
}
