<?php

declare(strict_types=1);

namespace App\Services\RemoteCli;

use App\Models\Site;

/**
 * php artisan adapter on top of {@see RemoteCli}.
 *
 * Symmetric structure to {@see WpCli}: same INSTANT_COMMANDS allowlist
 * shape, same risk classification table, unknown commands fall through
 * to RiskLevel::Destructive.
 *
 * `tinker` is a special case: input is runtime PHP, can't be statically
 * classified, treated as Destructive regardless. The same goes for
 * `migrate:fresh` and `migrate:wipe` (data loss).
 */
class Artisan extends RemoteCli
{
    /**
     * Subset of artisan commands that complete in <1s. Mostly the
     * inspect / list family.
     *
     * @return list<string>
     */
    protected function instantCommands(): array
    {
        return [
            'about',
            'env',
            'env:show',
            'route:list',
            'config:show',
            'config:cache',
            'config:clear',
            'cache:clear',
            'view:clear',
            'event:list',
            'queue:size',
            'queue:failed',
            'schedule:list',
            'migrate:status',
            'storage:link',
            'optimize:clear',
            'about',
            'package:discover',
        ];
    }

    /**
     * Risk classification per Q17. Anything not listed defaults to
     * Destructive (failsafe).
     */
    public function classifyRisk(string $command): RiskLevel
    {
        $command = trim($command);

        // READ — inspection only.
        $reads = [
            'about',
            'env', 'env:show', 'env:decrypt',
            'route:list', 'route:cache', 'route:clear',
            'config:show', 'config:cache', 'config:clear',
            'cache:clear',
            'view:clear', 'view:cache',
            'event:list', 'event:cache', 'event:clear',
            'queue:size', 'queue:failed', 'queue:monitor',
            'schedule:list', 'schedule:test',
            'migrate:status',
            'optimize', 'optimize:clear',
            'storage:link',
            'package:discover',
            'pail',
            'about',
            'inspire',
        ];
        foreach ($reads as $read) {
            if ($command === $read || str_starts_with($command, $read.' ')) {
                return RiskLevel::Read;
            }
        }

        // MUTATING-RECOVERABLE — forward operations with a clear
        // inverse (most migrations, most cache rebuilds, most makes).
        // Matched as exact command, or command-followed-by-space-arg.
        // Bare 'migrate' must NOT also catch 'migrate:rollback' — those
        // are different artisan commands, not args to the same command.
        $recoverable = [
            'migrate', 'migrate:install', 'migrate:refresh',
            'queue:work', 'queue:listen', 'queue:retry', 'queue:forget', 'queue:flush',
            'schedule:run', 'schedule:work',
            'horizon', 'horizon:pause', 'horizon:continue',
            'reverb:start', 'reverb:restart',
            'pulse:check', 'pulse:work',
            'storage:link',
            'vendor:publish',
            'breeze:install',
        ];
        foreach ($recoverable as $rec) {
            if ($command === $rec || str_starts_with($command, $rec.' ')) {
                return RiskLevel::MutatingRecoverable;
            }
        }

        // `make:*` is a whole prefix family (make:controller, make:model,
        // make:test, make:job, ...). All are file-creators with trivial
        // undo; treat the entire family as recoverable.
        if (str_starts_with($command, 'make:')) {
            return RiskLevel::MutatingRecoverable;
        }

        // Anything else falls through. Notable destructive commands
        // that this catches:
        //   migrate:rollback, migrate:reset, migrate:fresh, migrate:wipe,
        //   db:seed, db:wipe, tinker, eval, queue:clear, key:generate,
        //   optimize (cache compile), cache:forget,
        //   model:prune, schedule:interrupt
        return RiskLevel::Destructive;
    }

    public function kind(): Kind
    {
        return Kind::Artisan;
    }

    /**
     * `cd <site-root> && php artisan <command> <args>` so artisan
     * picks up the site's own .env / vendor / config.
     */
    protected function buildShellCommand(Site $site, string $command, array $args): string
    {
        $path = $site->document_root ?: $site->repository_path ?: '/home/dply/'.$site->slug;
        $escaped = array_map(escapeshellarg(...), $args);

        return sprintf(
            'cd %s && php artisan %s %s',
            escapeshellarg($path),
            $command,
            implode(' ', $escaped),
        );
    }
}
