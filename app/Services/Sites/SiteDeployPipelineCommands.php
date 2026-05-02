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
            SiteDeployStep::TYPE_NPM_RUN => $custom !== ''
                ? 'npm run '.escapeshellarg($custom)
                : 'npm run build',
            SiteDeployStep::TYPE_ARTISAN_MIGRATE => 'php artisan migrate --force --no-interaction',
            SiteDeployStep::TYPE_ARTISAN_CONFIG_CACHE => 'php artisan config:cache',
            SiteDeployStep::TYPE_ARTISAN_ROUTE_CACHE => 'php artisan route:cache',
            SiteDeployStep::TYPE_ARTISAN_VIEW_CACHE => 'php artisan view:cache',
            SiteDeployStep::TYPE_ARTISAN_OPTIMIZE => 'php artisan optimize --no-interaction',
            SiteDeployStep::TYPE_ARTISAN_OCTANE_INSTALL => 'php artisan octane:install --no-interaction',
            SiteDeployStep::TYPE_ARTISAN_REVERB_INSTALL => 'php artisan reverb:install --no-interaction',
            SiteDeployStep::TYPE_CUSTOM => $custom !== '' ? $custom : null,
            default => null,
        };
    }
}
