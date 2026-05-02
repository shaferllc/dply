<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Rename a site (display name and/or slug).
 *
 *   dply:site:rename <site> [--name=...] [--slug=...] [--dry-run] [--json]
 *
 * Either --name or --slug (or both) must be provided. Slug is
 * unique per (server_id, slug), so the new slug must not collide
 * with another site on the same server. If --slug is omitted but
 * --name is given, the slug is left alone (we don't auto-rederive
 * because that risks breaking deploy paths and webhook URLs).
 *
 * NOTE: this only renames the database row. The on-disk site
 * directory (/srv/sites/<slug>) is NOT moved — the next deploy
 * would use the new slug. Operators who care about path consistency
 * should follow with a teardown + redeploy.
 */
class RenameSiteCommand extends Command
{
    protected $signature = 'dply:site:rename
        {site : Site ID, slug, or name}
        {--name= : New display name}
        {--slug= : New slug (must be unique on the server)}
        {--dry-run : Report the proposed change without writing}
        {--json : Output as JSON}';

    protected $description = 'Rename a site (display name and/or slug).';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $newName = $this->option('name');
        $newSlug = $this->option('slug');
        if ($newName === null && $newSlug === null) {
            $this->error('Pass --name or --slug (or both).');

            return self::FAILURE;
        }

        $changes = [];
        if ($newName !== null) {
            $newName = trim((string) $newName);
            if ($newName === '') {
                $this->error('--name cannot be empty.');

                return self::FAILURE;
            }
            $changes['name'] = $newName;
        }

        if ($newSlug !== null) {
            $newSlug = Str::slug((string) $newSlug);
            if ($newSlug === '') {
                $this->error('--slug must produce a non-empty slug after normalization.');

                return self::FAILURE;
            }
            // Slug uniqueness is scoped per server; check before writing.
            $collision = Site::query()
                ->where('server_id', $site->server_id)
                ->where('slug', $newSlug)
                ->where('id', '!=', $site->id)
                ->exists();
            if ($collision) {
                $this->error(sprintf(
                    'Slug "%s" is already in use by another site on this server.',
                    $newSlug,
                ));

                return self::FAILURE;
            }
            $changes['slug'] = $newSlug;
        }

        $diff = [];
        foreach ($changes as $col => $val) {
            $diff[$col] = ['from' => $site->getAttribute($col), 'to' => $val];
        }

        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun) {
            $site->fill($changes)->save();
        }

        $payload = [
            'site_id' => $site->id,
            'dry_run' => $dryRun,
            'changes' => $diff,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $verb = $dryRun ? 'Would rename' : 'Renamed';
        $this->info("{$verb} site:");
        foreach ($diff as $col => $change) {
            $this->line(sprintf('  %-6s %s → %s', $col, $change['from'], $change['to']));
        }
        if (isset($changes['slug']) && ! $dryRun) {
            $this->newLine();
            $this->line('<fg=yellow>Note: on-disk site directory was NOT moved. Redeploy or run dply:redeploy-systemd if path consistency matters.</>');
        }

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
