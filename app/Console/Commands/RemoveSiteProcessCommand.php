<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Remove a SiteProcess by name.
 *
 *   dply:site:process-remove <site> <name> [--json]
 *
 * Refuses to remove the auto-created "web" process by default
 * (every site needs at least a web entry to receive HTTP traffic);
 * pass --force to override. Useful when migrating away from a
 * runtime that doesn't have a long-lived web process.
 *
 * No SSH, no systemd teardown — just deletes the row. To stop the
 * actual process on the server, follow with dply:teardown-systemd
 * for the unit, then redeploy.
 */
class RemoveSiteProcessCommand extends Command
{
    protected $signature = 'dply:site:process-remove
        {site : Site ID, slug, or name}
        {name : Process name to remove}
        {--force : Allow removing the "web" process}
        {--json : Output as JSON}';

    protected $description = 'Remove a SiteProcess row by name.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $name = trim((string) $this->argument('name'));
        if ($name === '') {
            $this->error('Process name cannot be empty.');

            return self::FAILURE;
        }

        $process = $site->processes()->where('name', $name)->first();
        if ($process === null) {
            $this->error("Process not found: {$name}");

            return self::FAILURE;
        }

        if ($name === 'web' && ! (bool) $this->option('force')) {
            $this->error('Refusing to remove the "web" process without --force.');

            return self::FAILURE;
        }

        $process->delete();

        $payload = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'removed' => $name,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf('Removed process "%s" from %s.', $name, $site->name));

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
