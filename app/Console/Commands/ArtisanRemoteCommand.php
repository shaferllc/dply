<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\User;
use App\Services\RemoteCli\Artisan as ArtisanService;
use App\Services\RemoteCli\RemoteCliPermissionDeniedException;
use Illuminate\Console\Command;

/**
 * Umbrella CLI surface for php artisan against a remote dply-managed
 * Laravel site (Q21 v1). Symmetric to {@see WpCliCommand}.
 *
 *   dply:artisan <site> -- migrate:status
 *   dply:artisan <site> --json -- schedule:list --json
 *   dply:artisan <site> --no-confirm -- migrate:rollback --step=1
 */
class ArtisanRemoteCommand extends Command
{
    protected $signature = 'dply:artisan
        {site : Site name or slug to target}
        {args?* : artisan command + args (everything after `--`)}
        {--user= : User email to act as (defaults to site owner; gates the permission check)}
        {--json : Emit a JSON envelope on stdout instead of streaming the raw command output}
        {--no-confirm : Skip the destructive-command confirmation prompt (CI-safe)}';

    protected $description = 'Run any php artisan command against a dply-managed Laravel site through the Artisan service.';

    public function handle(ArtisanService $artisan): int
    {
        $siteName = (string) $this->argument('site');
        $rawArgs = (array) $this->argument('args');

        if ($rawArgs === []) {
            $this->error('Pass the artisan invocation after `--`. Example: dply:artisan my-app -- migrate:status');

            return self::FAILURE;
        }

        $site = $this->resolveSite($siteName);
        if ($site === null) {
            $this->error("Site [{$siteName}] not found.");

            return self::FAILURE;
        }

        $caller = $this->resolveActingUser($site);
        $command = (string) array_shift($rawArgs);
        $args = array_map('strval', $rawArgs);

        $risk = $artisan->classifyRisk($command);
        if ($risk->requiresConfirmation() && ! $this->option('no-confirm')) {
            $confirmed = $this->confirm("[{$risk->value}] php artisan {$command} ".implode(' ', $args).' on '.$site->name.' — continue?');
            if (! $confirmed) {
                $this->warn('Aborted.');

                return self::FAILURE;
            }
        }

        try {
            $result = $artisan->run(site: $site, command: $command, args: $args, queuedBy: $caller);
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
                $this->info("Queued (run {$result->run->id}).");
            }
        }

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
}
