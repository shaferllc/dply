<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Heroku-style `ps` for a site — list its SiteProcess rows.
 *
 * Per the strategy memo: "`dply ps` CLI shape (Node-dev affordance)".
 * Useful for seeing what processes the deploy pipeline (or the URL-
 * first detection layer) created for a site, plus their commands /
 * scale / active state.
 *
 * Lookup is by ID, slug, or name (slugs are unique per organization
 * but not globally — first match wins). Pass --json for a
 * machine-readable shape suitable for scripting.
 */
class SitePsCommand extends Command
{
    protected $signature = 'dply:site:ps
        {site : Site ID, slug, or name}
        {--json : Output as JSON instead of a table}';

    protected $description = 'List the SiteProcess rows (web / worker / scheduler / custom) for a site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("No site found matching: {$needle}");

            return self::FAILURE;
        }

        // Sort by canonical type priority (web → worker → scheduler →
        // custom) then name. Done in PHP because the orderByRaw shape
        // for this varies across drivers and the row count is small.
        $typePriority = [
            SiteProcess::TYPE_WEB => 0,
            SiteProcess::TYPE_WORKER => 1,
            SiteProcess::TYPE_SCHEDULER => 2,
        ];
        $processes = $site->processes()
            ->orderBy('name')
            ->get()
            ->sortBy(fn (SiteProcess $p) => [$typePriority[$p->type] ?? 9, $p->name])
            ->values();

        if ($this->option('json')) {
            $this->line(json_encode($this->jsonShape($site, $processes), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderTable($site, $processes);

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

    /**
     * @param  Collection<int, SiteProcess>  $processes
     */
    private function renderTable(Site $site, $processes): void
    {
        $runtimeLabel = $site->runtimeKey();
        $version = $site->runtimeVersion() ?? '<unset>';
        $port = $site->internal_port !== '' ? $site->internal_port : '<none>';
        $this->newLine();
        $this->line("<fg=cyan>Site</> <fg=white;options=bold>{$site->name}</> ({$site->slug}, {$site->id})");
        $this->line("<fg=cyan>Runtime</> {$runtimeLabel}@{$version}  <fg=cyan>Internal port</> {$port}");
        $this->newLine();

        if ($processes->isEmpty()) {
            $this->warn('No processes registered yet.');

            return;
        }

        $rows = $processes->map(fn (SiteProcess $p) => [
            $p->type,
            $p->name,
            (string) ($p->scale ?? 1),
            $p->is_active ? 'yes' : 'no',
            $this->truncate($p->command ?? '<unset>', 80),
        ])->all();

        $this->table(['type', 'name', 'scale', 'active', 'command'], $rows);
    }

    /**
     * @param  Collection<int, SiteProcess>  $processes
     * @return array<string, mixed>
     */
    private function jsonShape(Site $site, $processes): array
    {
        return [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
                'runtime' => $site->runtimeKey(),
                'runtime_version' => $site->runtimeVersion(),
                'internal_port' => $site->internal_port,
            ],
            'processes' => $processes->map(fn (SiteProcess $p) => [
                'id' => $p->id,
                'type' => $p->type,
                'name' => $p->name,
                'command' => $p->command,
                'scale' => (int) ($p->scale ?? 1),
                'is_active' => (bool) $p->is_active,
            ])->values()->all(),
        ];
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max - 1).'…';
    }
}
