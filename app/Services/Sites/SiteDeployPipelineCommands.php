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
            // pnpm and yarn are not installed alongside Node — mise installs the
            // runtime, not the alternate package managers — so a bare `pnpm`
            // exits 127 "command not found". Node ships corepack, which can
            // fetch and run the pinned manager on demand, so fall back to it
            // rather than requiring a separate server-side install step.
            SiteDeployStep::TYPE_YARN_INSTALL => self::viaCorepack('yarn', 'yarn install --frozen-lockfile'),
            SiteDeployStep::TYPE_PNPM_INSTALL => self::viaCorepack('pnpm', 'pnpm install --frozen-lockfile'),
            // Bun is a runtime in its own right, not a corepack-managed manager.
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

    /**
     * Run a JS package manager, preferring the real binary and falling back to
     * corepack (bundled with Node) when it is not installed.
     *
     * `corepack <manager>` downloads and runs the version pinned in
     * package.json's packageManager field, or a recent default. Without this a
     * pnpm/yarn repo fails at the install step with exit 127 on any box that
     * only has Node.
     */
    private static function viaCorepack(string $manager, string $command): string
    {
        return sprintf(
            'if command -v %1$s >/dev/null 2>&1; then %2$s; '
            .'elif command -v corepack >/dev/null 2>&1; then corepack %2$s; '
            .'else echo "%1$s is not installed and corepack is unavailable — install %1$s on this server." >&2; exit 127; fi',
            $manager,
            $command,
        );
    }
}
