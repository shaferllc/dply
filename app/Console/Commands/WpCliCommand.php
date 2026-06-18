<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\User;
use App\Modules\RemoteCli\Services\RemoteCliPermissionDeniedException;
use App\Modules\RemoteCli\Services\WpCli;
use Illuminate\Console\Command;

/**
 * Umbrella CLI surface for wp-cli (Q21 v1).
 *
 *   dply:wp <site> -- plugin install woocommerce --activate
 *   dply:wp <site> --json -- plugin list --format=json
 *   dply:wp <site> --user=ops@acme.com -- option get siteurl
 *
 * Routes through the {@see WpCli} service so risk classification,
 * permission gating, audit logging, and INSTANT-vs-async dispatch
 * all match the web UI surface (no policy drift between transports).
 */
class WpCliCommand extends Command
{
    protected $signature = 'dply:wp
        {site : Site name or slug to target}
        {args?* : wp-cli command + args (everything after `--`)}
        {--user= : User email to act as (defaults to the site owner; gates the permission check)}
        {--json : Emit a JSON envelope on stdout instead of streaming the raw command output}
        {--no-confirm : Skip the destructive-command confirmation prompt (CI-safe)}';

    protected $description = 'Run any wp-cli command against a dply-managed site through the WpCli service.';

    public function handle(WpCli $wpcli): int
    {
        $siteName = (string) $this->argument('site');
        $rawArgs = (array) $this->argument('args');

        if ($rawArgs === []) {
            $this->error('Pass the wp-cli invocation after `--`. Example: dply:wp my-blog -- plugin list');

            return self::FAILURE;
        }

        $site = $this->resolveSite($siteName);
        if ($site === null) {
            $this->error("Site [{$siteName}] not found.");

            return self::FAILURE;
        }

        $caller = $this->resolveActingUser($site);
        [$command, $args] = $this->splitCommandAndArgs($rawArgs);
        $risk = $wpcli->classifyRisk($command);

        if ($risk->requiresConfirmation() && ! $this->option('no-confirm')) {
            $confirmed = $this->confirm("[{$risk->value}] wp {$command} ".implode(' ', $args).' on '.$site->name.' — continue?');
            if (! $confirmed) {
                $this->warn('Aborted.');

                return self::FAILURE;
            }
        }

        try {
            $result = $wpcli->run(site: $site, command: $command, args: $args, queuedBy: $caller);
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'run_id' => $result->run->id,
                'status' => $result->status(),
                'mode' => $result->run->mode,
                'risk' => $result->run->risk->value,
                'exit_code' => $result->exitCode(),
                'stdout' => $result->stdout(),
                'stderr' => $result->stderr(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            if ($result->stdout() !== '') {
                $this->line($result->stdout());
            }
            if ($result->stderr() !== '') {
                $this->getOutput()->writeln('<fg=red>'.$result->stderr().'</>');
            }
            if ($result->isQueued()) {
                $this->info("Queued (run {$result->run->id}). Tail with: dply:wp:run:tail {$result->run->id}");
            }
        }

        // Pass through the underlying command's exit code so CI scripts
        // can branch on it (Q21 — exit code propagation).
        return $result->exitCode() ?? ($result->isFailed() ? self::FAILURE : self::SUCCESS);
    }

    private function resolveSite(string $nameOrSlug): ?Site
    {
        return Site::query()
            ->where(function ($q) use ($nameOrSlug) {
                $q->where('name', $nameOrSlug)->orWhere('slug', $nameOrSlug);
            })
            ->first();
    }

    private function resolveActingUser(Site $site): ?User
    {
        $email = $this->option('user');
        if (is_string($email) && $email !== '') {
            return User::query()->where('email', $email)->first();
        }

        return $site->user ?? null;
    }

    /**
     * @param  list<string>  $rawArgs
     * @return array{0: string, 1: list<string>}
     */
    private function splitCommandAndArgs(array $rawArgs): array
    {
        // Recombine `subject verb` (e.g. "plugin install") as a
        // single command if the first two args don't start with "--".
        $command = '';
        $tail = [];
        $i = 0;
        while ($i < count($rawArgs) && ! str_starts_with((string) $rawArgs[$i], '-')) {
            $command = trim($command.' '.$rawArgs[$i]);
            $i++;
            // Stop after two non-option tokens — wp-cli commands are
            // typically `verb` or `subject verb`. Further tokens are args.
            if (substr_count($command, ' ') >= 1) {
                break;
            }
        }
        for (; $i < count($rawArgs); $i++) {
            $tail[] = (string) $rawArgs[$i];
        }

        return [$command, $tail];
    }
}
