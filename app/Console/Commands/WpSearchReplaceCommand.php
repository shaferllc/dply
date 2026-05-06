<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSiteForCliCommand;
use App\Services\RemoteCli\RemoteCliPermissionDeniedException;
use App\Services\RemoteCli\WpCli;
use Illuminate\Console\Command;

/**
 * dply:wp:search-replace <site> <from> <to> [--no-confirm] [--dry-run] [--user=]
 *
 * The WP-aware DB rewrite — wraps `wp search-replace` with the
 * canonical safe flags (--all-tables, --skip-columns=guid) so the
 * one operation everyone gets wrong manually is a single command.
 *
 * Always destructive — confirms unless --no-confirm or --dry-run.
 */
class WpSearchReplaceCommand extends Command
{
    use ResolvesSiteForCliCommand;

    protected $signature = 'dply:wp:search-replace
        {site : Site name or slug}
        {from : The string to find (typically the old hostname)}
        {to : The replacement string (typically the new hostname)}
        {--user= : User email to act as (audit trail + permission gate)}
        {--no-confirm : Skip the destructive-action prompt}
        {--dry-run : Run wp search-replace --dry-run; report counts without writing}';

    protected $description = 'WP-aware search-replace across all tables (skips GUID column).';

    public function handle(WpCli $wpcli): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $caller = $this->resolveActingUser($site, $this->option('user'));
        $from = (string) $this->argument('from');
        $to = (string) $this->argument('to');

        $args = [$from, $to, '--all-tables', '--skip-columns=guid'];
        if ($this->option('dry-run')) {
            $args[] = '--dry-run';
        } elseif (! $this->option('no-confirm')) {
            $confirmed = $this->confirm("Run search-replace on {$site->name}: '{$from}' → '{$to}'? This rewrites every match across all tables.");
            if (! $confirmed) {
                $this->warn('Aborted.');

                return self::FAILURE;
            }
        }

        try {
            $result = $wpcli->run(
                site: $site,
                command: 'search-replace',
                args: $args,
                queuedBy: $caller,
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result->stdout() !== '') {
            $this->line($result->stdout());
        }
        if ($result->stderr() !== '') {
            $this->getOutput()->writeln('<fg=red>'.$result->stderr().'</>');
        }

        return $result->exitCode() ?? ($result->isFailed() ? self::FAILURE : self::SUCCESS);
    }
}
