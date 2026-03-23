<?php

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Models\SiteDeployStep;

class SiteDeployPipelineRunner
{
    public function run(RemoteShell $ssh, Site $site, string $workingDirectory): string
    {
        $site->loadMissing('deploySteps');
        $cwd = escapeshellarg($workingDirectory);
        $log = '';

        foreach ($site->deploySteps->sortBy('sort_order')->values() as $step) {
            /** @var SiteDeployStep $step */
            $cmd = $this->resolveShellCommand($step);
            if ($cmd === null || $cmd === '') {
                continue;
            }
            $timeout = max(30, min(3600, (int) ($step->timeout_seconds ?? 900)));
            $log .= "\n--- pipeline: {$step->step_type} ---\n";
            $log .= $ssh->exec("cd {$cwd} && ({$cmd}) 2>&1", $timeout);
        }

        return $log;
    }

    protected function resolveShellCommand(SiteDeployStep $step): ?string
    {
        $custom = trim((string) ($step->custom_command ?? ''));

        return match ($step->step_type) {
            SiteDeployStep::TYPE_COMPOSER_INSTALL => 'composer install --no-dev --no-interaction --prefer-dist --no-ansi',
            SiteDeployStep::TYPE_NPM_CI => 'npm ci --no-audit --no-fund',
            SiteDeployStep::TYPE_NPM_INSTALL => 'npm install --no-audit --no-fund',
            SiteDeployStep::TYPE_NPM_RUN => $custom !== ''
                ? 'npm run '.escapeshellarg($custom)
                : 'npm run build',
            SiteDeployStep::TYPE_ARTISAN_MIGRATE => 'php artisan migrate --force --no-interaction',
            SiteDeployStep::TYPE_ARTISAN_CONFIG_CACHE => 'php artisan config:cache',
            SiteDeployStep::TYPE_ARTISAN_ROUTE_CACHE => 'php artisan route:cache',
            SiteDeployStep::TYPE_ARTISAN_VIEW_CACHE => 'php artisan view:cache',
            SiteDeployStep::TYPE_ARTISAN_OPTIMIZE => 'php artisan optimize',
            SiteDeployStep::TYPE_CUSTOM => $custom !== '' ? $custom : null,
            default => null,
        };
    }
}
