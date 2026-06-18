<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSiteForCliCommand;
use App\Modules\RemoteCli\Services\WpCli;
use Illuminate\Console\Command;

/**
 * dply:wp:hardening:apply <site> [--user=]
 *
 * Reapply the Q18 opinionated-secure hardening defaults idempotently.
 * Useful in CI when standing up a WordPress site that wasn't scaffolded
 * by dply (e.g. an imported install) — dply just-applies its
 * defaults regardless of how the site got there.
 *
 * Mirrors what ScaffoldWordPressPipeline::stepApplyHardening() does
 * during scaffold; both routes share the same `wp config set` calls
 * via the WpCli service so audit trails read uniformly.
 */
class WpHardeningApplyCommand extends Command
{
    use ResolvesSiteForCliCommand;

    protected $signature = 'dply:wp:hardening:apply
        {site : Site name or slug}
        {--user= : User email to act as (audit trail + permission gate)}';

    protected $description = 'Apply dply\'s opinionated WordPress hardening defaults (idempotent).';

    public function handle(WpCli $wpcli): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $caller = $this->resolveActingUser($site, $this->option('user'));

        // Each constant is its own wp-cli call so partial failure
        // (e.g. one constant rejected) doesn't block the rest.
        $opinions = [
            'DISALLOW_FILE_EDIT',
            'FORCE_SSL_ADMIN',
            'DISABLE_WP_CRON',
        ];

        $applied = [];
        $failed = [];
        foreach ($opinions as $constant) {
            try {
                $wpcli->run(
                    site: $site,
                    command: 'config set',
                    args: [$constant, 'true', '--raw', '--type=constant'],
                    queuedBy: $caller,
                );
                $applied[] = $constant;
            } catch (\Throwable $e) {
                $failed[$constant] = $e->getMessage();
            }
        }

        foreach ($applied as $constant) {
            $this->info("✓ {$constant}");
        }
        foreach ($failed as $constant => $msg) {
            $this->getOutput()->writeln("<fg=red>✗ {$constant}: {$msg}</>");
        }

        // Mirror the meta update that the Hardening tab writes so the
        // sub-tab UI reflects what the CLI just did.
        $meta = $site->meta;
        $opinionRows = [
            ['key' => 'disallow_file_edit', 'enabled' => in_array('DISALLOW_FILE_EDIT', $applied, true)],
            ['key' => 'force_ssl_admin', 'enabled' => in_array('FORCE_SSL_ADMIN', $applied, true)],
            ['key' => 'disable_wp_cron', 'enabled' => in_array('DISABLE_WP_CRON', $applied, true)],
        ];
        $meta['scaffold']['hardening'] = $opinionRows;
        $site->meta = $meta;
        $site->save();

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
