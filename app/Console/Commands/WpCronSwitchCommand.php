<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSiteForCliCommand;
use App\Modules\WordPress\Jobs\SwitchWordPressCronHandlerJob;
use Illuminate\Console\Command;

/**
 * dply:wp:cron:switch <site> --to=system|wp-cron [--user=]
 *
 * The infrastructure flip nobody bothers to do manually. Mirrors
 * the WordPress Cron sub-tab's "Switch to system cron" CTA.
 */
class WpCronSwitchCommand extends Command
{
    use ResolvesSiteForCliCommand;

    protected $signature = 'dply:wp:cron:switch
        {site : Site name or slug}
        {--to=system : Target handler — "system" disables wp-cron, "wp-cron" re-enables HTTP-driven wp-cron}
        {--user= : User email to act as}';

    protected $description = 'Flip a WordPress site between system cron and HTTP-driven wp-cron.';

    public function handle(): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $target = (string) $this->option('to');
        if (! in_array($target, ['system', 'wp-cron'], true)) {
            $this->error('--to must be "system" or "wp-cron".');

            return self::FAILURE;
        }

        $caller = $this->resolveActingUser($site, $this->option('user'));

        // Same job as the Cron tab, run inline: it installs the crontab entry
        // before disabling wp-cron. Flipping only DISABLE_WP_CRON, as this
        // command used to, silently stopped every scheduled task.
        SwitchWordPressCronHandlerJob::dispatchSync((string) $site->id, $target, $caller?->id !== null ? (string) $caller->id : null);

        $error = data_get($site->fresh()?->meta, 'wp_cron.error');
        if (is_string($error) && $error !== '') {
            $this->error('Cron switch failed: '.$error);

            return self::FAILURE;
        }

        $this->info("Cron handler switched to {$target}.");

        return self::SUCCESS;
    }
}
