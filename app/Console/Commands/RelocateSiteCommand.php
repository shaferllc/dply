<?php

namespace App\Console\Commands;

use App\Jobs\RelocateSiteFilesJob;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RelocateSiteCommand extends Command
{
    protected $signature = 'dply:site:relocate
        {site? : Site ID, slug, or name (omit when using --all)}
        {--path= : Target base path (defaults to /home/dply/<domain>)}
        {--all : Relocate every VM site to the /home/dply/<domain> convention}';

    protected $description = 'Relocate a site\'s files to /home/dply/<domain> (move on server, repoint paths, reload vhost).';

    public function handle(): int
    {
        $targetOverride = trim((string) $this->option('path')) ?: null;

        if ($this->option('all')) {
            if ($targetOverride !== null) {
                $this->error('--path cannot be combined with --all (each site gets its own /home/dply/<domain>).');

                return self::FAILURE;
            }

            return $this->relocateAll();
        }

        $needle = trim((string) $this->argument('site'));
        if ($needle === '') {
            $this->error('Provide a site (ID, slug, or name), or use --all.');

            return self::FAILURE;
        }

        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        return $this->dispatchFor($site, $targetOverride);
    }

    private function relocateAll(): int
    {
        $sites = Site::query()
            ->whereNotNull('server_id')
            ->whereNotNull('repository_path')
            ->where('repository_path', '!=', '')
            ->get();

        if ($sites->isEmpty()) {
            $this->info('No VM sites with a repository path to relocate.');

            return self::SUCCESS;
        }

        $this->table(
            ['Site', 'From', 'To'],
            $sites->map(fn (Site $s): array => [
                $s->slug ?: $s->id,
                rtrim($s->effectiveRepositoryPath(), '/'),
                $s->conventionalRepositoryPath(),
            ])->all()
        );

        if (! $this->confirm('Relocate all '.$sites->count().' site(s) above? Files will be moved on each server.')) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $sites->each(fn (Site $s) => RelocateSiteFilesJob::dispatch($s->id));
        $this->info('Queued relocation for '.$sites->count().' site(s).');

        return self::SUCCESS;
    }

    private function dispatchFor(Site $site, ?string $targetOverride): int
    {
        if ($site->server_id === null) {
            $this->error('Site has no server; nothing to relocate.');

            return self::FAILURE;
        }

        $target = $targetOverride ?? $site->conventionalRepositoryPath();
        $from = rtrim($site->effectiveRepositoryPath(), '/');

        $this->line("Relocating <info>{$site->slug}</info>:");
        $this->line("  from: {$from}");
        $this->line("  to:   {$target}");

        RelocateSiteFilesJob::dispatch($site->id, $target);
        $this->info('Queued. The vhost will be re-rendered and reloaded once files are moved.');

        return self::SUCCESS;
    }

    private function resolveSite(string $needle): ?Site
    {
        return Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
