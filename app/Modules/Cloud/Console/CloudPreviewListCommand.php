<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Modules\Cloud\Actions\CreateCloudPreviewSite;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * List active preview deployments for a parent source-mode cloud site.
 *
 *   dply:cloud:preview:list <parent> [--json]
 */
class CloudPreviewListCommand extends Command
{
    protected $signature = 'dply:cloud:preview:list
        {parent : Parent site ID, slug, or name}
        {--json : Output as JSON}';

    protected $description = 'List preview deployments for a parent cloud site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('parent');
        $parent = $this->resolveSite($needle);
        if ($parent === null) {
            $this->error("Parent site not found: {$needle}");

            return self::FAILURE;
        }

        $previews = CreateCloudPreviewSite::listForParent($parent);

        $rows = $previews->map(function (Site $site): array {
            $meta = $site->meta;
            $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];

            return [
                'site' => $site->name,
                'slug' => $site->slug,
                'branch' => $container['preview_branch'] ?? null,
                'pr' => $container['preview_pr_number'] ?? null,
                'status' => $site->status,
                'live_url' => $site->containerLiveUrl(),
            ];
        })->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'parent' => $parent->name,
                'total' => count($rows),
                'previews' => $rows,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line('<fg=gray>No preview deployments for this site yet.</>');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line(sprintf('<fg=cyan>Previews for</> %s', $parent->name));
        $this->newLine();

        $this->table(
            ['preview', 'branch', 'pr', 'status', 'live url'],
            array_map(fn (array $r): array => [
                $r['site'],
                $r['branch'] ?? '—',
                $r['pr'] !== null ? '#'.$r['pr'] : '—',
                $r['status'],
                $r['live_url'] ?? '—',
            ], $rows),
        );

        return self::SUCCESS;
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
