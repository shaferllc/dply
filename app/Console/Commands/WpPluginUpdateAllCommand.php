<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSiteForCliCommand;
use App\Modules\RemoteCli\Services\RemoteCliPermissionDeniedException;
use App\Modules\RemoteCli\Services\WpCli;
use Illuminate\Console\Command;

/**
 * dply:wp:plugin:update-all <site> [--user=]
 *
 * Bulk-update every plugin reporting "available". Mirrors the
 * Plugins sub-tab's "Update all" CTA. Async dispatch (mutating
 * but recoverable — WP keeps the prior version's files until the
 * update succeeds). Returns the run id so CI can `dply:wp:run:tail`
 * it (forward-looking — that command is a follow-up).
 */
class WpPluginUpdateAllCommand extends Command
{
    use ResolvesSiteForCliCommand;

    protected $signature = 'dply:wp:plugin:update-all
        {site : Site name or slug}
        {--user= : User email to act as}
        {--json : Emit a JSON envelope on stdout}';

    protected $description = 'Update every WordPress plugin reporting "available" on this site.';

    public function handle(WpCli $wpcli): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $caller = $this->resolveActingUser($site, $this->option('user'));

        try {
            $result = $wpcli->run(
                site: $site,
                command: 'plugin update',
                args: ['--all'],
                queuedBy: $caller,
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'run_id' => $result->run->id,
                'status' => $result->status(),
                'mode' => $result->run->mode,
            ], JSON_PRETTY_PRINT));
        } elseif ($result->isQueued()) {
            $this->info("Queued (run {$result->run->id}). Tail with: dply:wp {$site->name} -- plugin status");
        } else {
            $this->line($result->stdout());
        }

        return $result->exitCode() ?? ($result->isFailed() ? self::FAILURE : self::SUCCESS);
    }
}
