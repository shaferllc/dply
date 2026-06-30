<?php

namespace App\Services\Sites;

use App\Models\SiteDeployStep;

/**
 * Inner shell fragment for a {@see SiteDeployStep} type (no `cd`; used by
 * {@see SiteDeployPipelineRunner} and {@see LaravelSiteSshSetupRunner}).
 */
final class SiteDeployPipelineCommands
{
    public static function fragmentFor(string $stepType, string $custom = ''): ?string
    {
        $custom = trim($custom);

        return match ($stepType) {
            SiteDeployStep::TYPE_COMPOSER_INSTALL => 'composer install --no-dev --no-interaction --prefer-dist --no-ansi',
            SiteDeployStep::TYPE_NPM_CI => 'npm ci --no-audit --no-fund',
            SiteDeployStep::TYPE_NPM_INSTALL => 'npm install --no-audit --no-fund',
            // `--if-present` makes a missing script (e.g. no "build" in
            // package.json) a clean no-op instead of a deploy-failing error.
            SiteDeployStep::TYPE_NPM_RUN => $custom !== ''
                ? 'npm run '.escapeshellarg($custom).' --if-present'
                : 'npm run build --if-present',
            SiteDeployStep::TYPE_YARN_INSTALL => 'yarn install --frozen-lockfile',
            SiteDeployStep::TYPE_PNPM_INSTALL => 'pnpm install --frozen-lockfile',
            SiteDeployStep::TYPE_BUN_INSTALL => 'bun install --frozen-lockfile',
            SiteDeployStep::TYPE_ARTISAN_MIGRATE => 'php artisan migrate --force --no-interaction',
            SiteDeployStep::TYPE_ARTISAN_MIGRATE_PRETEND => 'php artisan migrate --pretend --force',
            SiteDeployStep::TYPE_ARTISAN_CONFIG_CACHE => 'php artisan config:cache',
            SiteDeployStep::TYPE_ARTISAN_ROUTE_CACHE => 'php artisan route:cache',
            SiteDeployStep::TYPE_ARTISAN_VIEW_CACHE => 'php artisan view:cache',
            SiteDeployStep::TYPE_ARTISAN_OPTIMIZE => 'php artisan optimize --no-interaction',
            SiteDeployStep::TYPE_ARTISAN_OCTANE_INSTALL => 'php artisan octane:install --no-interaction',
            SiteDeployStep::TYPE_ARTISAN_REVERB_INSTALL => 'php artisan reverb:install --no-interaction',
            SiteDeployStep::TYPE_ARTISAN_STORAGE_LINK => 'php artisan storage:link',
            SiteDeployStep::TYPE_ARTISAN_EVENT_CACHE => 'php artisan event:cache',
            SiteDeployStep::TYPE_ARTISAN_QUEUE_RESTART => 'php artisan queue:restart',
            SiteDeployStep::TYPE_ARTISAN_HORIZON_TERMINATE => 'php artisan horizon:terminate',
            SiteDeployStep::TYPE_ARTISAN_DB_SEED => 'php artisan db:seed --force --no-interaction',
            SiteDeployStep::TYPE_ARTISAN_CACHE_CLEAR => 'php artisan cache:clear',
            SiteDeployStep::TYPE_CUSTOM => $custom !== '' ? $custom : null,
            default => null,
        };
    }
}
