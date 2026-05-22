<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSiteForCliCommand;
use App\Services\RemoteCli\RemoteCliPermissionDeniedException;
use App\Services\RemoteCli\WpCli;
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

    public function handle(WpCli $wpcli): int
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

        try {
            if ($target === 'system') {
                $wpcli->run(
                    site: $site,
                    command: 'config set',
                    args: ['DISABLE_WP_CRON', 'true', '--raw', '--type=constant'],
                    queuedBy: $caller,
                );
            } else {
                $wpcli->run(
                    site: $site,
                    command: 'config delete',
                    args: ['DISABLE_WP_CRON', '--type=constant'],
                    queuedBy: $caller,
                );
            }
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['wp_cron'] = ['handler' => $target === 'system' ? 'system_cron' : 'wp_cron', 'switched_at' => now()->toISOString()];
        $site->meta = $meta;
        $site->save();

        $this->info("Cron handler switched to {$target}.");

        return self::SUCCESS;
    }
}
