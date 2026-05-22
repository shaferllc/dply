<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Servers\ServerCreatePresetCatalog;
use Illuminate\Console\Command;

/**
 * Catalog of server-create wizard presets.
 *
 *   dply:list-presets [--json] [--id=]
 *
 * Lists every preset's id + name + description plus a one-line
 * summary of the role / runtime / database / cache it pre-fills.
 * Useful for ops review (and CI scripts that spin up servers via
 * the API) when the wizard isn't an option.
 *
 * --id filters to a single preset and shows the full meta payload.
 */
class ListPresetsCommand extends Command
{
    protected $signature = 'dply:list-presets
        {--id= : Show the full meta payload for one preset}
        {--json : Output as JSON}';

    protected $description = 'List server-create wizard presets and what each bundles.';

    public function handle(ServerCreatePresetCatalog $catalog): int
    {
        $id = (string) ($this->option('id') ?? '');
        if ($id !== '') {
            return $this->renderOne($catalog, $id);
        }

        $presets = $catalog->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'presets' => array_map(fn (array $p) => [
                    'id' => $p['id'],
                    'name' => $p['name'],
                    'description' => $p['description'],
                    'role' => $p['role'],
                    'webserver' => $p['webserver'],
                    'php_version' => $p['php_version'],
                    'database' => $p['database'],
                    'cache' => $p['cache'],
                    'runtimes' => $p['runtimes'],
                    'featured' => $p['featured'],
                ], $presets),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Server-create wizard presets</>');
        $this->newLine();

        $rows = [];
        foreach ($presets as $preset) {
            $marker = $preset['featured'] ? ' ★' : '';
            $stack = array_filter([
                $preset['php_version'] ? 'PHP '.$preset['php_version'] : null,
                ...array_map(fn ($v, $k) => ucfirst($k).' '.$v, $preset['runtimes'], array_keys($preset['runtimes'])),
            ]);
            $rows[] = [
                $preset['id'].$marker,
                $preset['name'],
                $preset['role'],
                implode(' + ', $stack) ?: '—',
                $preset['database'] ?? '—',
            ];
        }

        $this->table(['id', 'name', 'role', 'runtimes', 'database'], $rows);
        $this->newLine();
        $this->line('<fg=gray>★ = featured (surfaced first in the wizard).</>');
        $this->line('<fg=gray>Use </><fg=white>dply:list-presets --id=&lt;id&gt;</><fg=gray> for the full meta payload.</>');

        return self::SUCCESS;
    }

    private function renderOne(ServerCreatePresetCatalog $catalog, string $id): int
    {
        $preset = $catalog->find($id);
        if ($preset === null) {
            $this->error("Preset not found: {$id}");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'preset' => $preset,
                'server_meta' => $catalog->toServerMeta($id),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>'.$preset['name'].'</> <fg=gray>('.$preset['id'].')</>');
        $this->line($preset['description']);
        $this->newLine();

        $rows = [
            ['role', $preset['role']],
            ['webserver', $preset['webserver'] ?? '—'],
            ['PHP version', $preset['php_version'] ?? '—'],
            ['database', $preset['database'] ?? '—'],
            ['cache', $preset['cache'] ?? '—'],
            ['featured', $preset['featured'] ? 'yes' : 'no'],
        ];
        foreach ($preset['runtimes'] as $runtime => $version) {
            $rows[] = ["runtime: {$runtime}", $version];
        }

        $this->table(['key', 'value'], $rows);

        return self::SUCCESS;
    }
}
