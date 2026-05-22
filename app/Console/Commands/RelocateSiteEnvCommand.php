<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PushSiteEnvJob;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Move a site's `.env` file to a custom path outside the docroot.
 *
 *   dply:site:env-relocate <site>                        # propose default
 *   dply:site:env-relocate <site> --to=/etc/dply/foo.env # explicit path
 *   dply:site:env-relocate <site> --reset                # back to default
 *
 * Default proposed location: /etc/dply/<slug>.env. The pusher job creates
 * the parent directory, copies the file there, chowns to root:<site-user>
 * primary group, and chmods 640 so only the site's runtime can read it.
 *
 * The previous file is NOT removed — operators clean up old paths manually
 * once they've verified the new location works.
 */
class RelocateSiteEnvCommand extends Command
{
    protected $signature = 'dply:site:env-relocate
        {site : Site ID, slug, or name}
        {--to= : Absolute path on the host (defaults to /etc/dply/<slug>.env)}
        {--reset : Clear the override and revert to the default path}
        {--no-push : Save the new path but skip dispatching the push job}';

    protected $description = 'Relocate a site\'s .env file outside the docroot.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        if ((bool) $this->option('reset')) {
            $site->forceFill(['env_file_path' => null])->save();
            $this->info(sprintf('Reset .env path for %s — using default %s.', $site->name, $site->effectiveEnvFilePath()));

            return self::SUCCESS;
        }

        $to = trim((string) ($this->option('to') ?? ''));
        if ($to === '') {
            $to = '/etc/dply/'.$site->slug.'.env';
            $this->info(sprintf('No --to provided; using default %s', $to));
        }

        if (! str_starts_with($to, '/')) {
            $this->error('--to must be an absolute path.');

            return self::FAILURE;
        }
        if (str_contains($to, '\\') || str_contains($to, "\0")) {
            $this->error('--to must not contain backslashes or null bytes.');

            return self::FAILURE;
        }

        $site->forceFill(['env_file_path' => $to])->save();
        $this->info(sprintf('Saved .env path for %s — %s.', $site->name, $to));

        if ((bool) $this->option('no-push')) {
            $this->line('Skipped push (--no-push). Run dply:site:env-push or click Push on the dashboard when ready.');

            return self::SUCCESS;
        }

        if (! $site->server?->hostCapabilities()->supportsEnvPushToHost()) {
            $this->warn('Site runtime does not support pushing a server .env file. Path saved; no push dispatched.');

            return self::SUCCESS;
        }

        PushSiteEnvJob::dispatch($site->id);
        $this->info('Push dispatched — track progress in the Environment page banner.');

        return self::SUCCESS;
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
