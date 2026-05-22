<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Servers\MiseInstallScriptBuilder;
use Illuminate\Console\Command;

/**
 * Catalog command — lists the runtimes dply knows how to manage on
 * a server, plus the canonical recent versions used by the
 * server-create wizard's polyglot preset.
 *
 *   dply:list-runtimes [--json]
 *
 * Read-only ops discovery. Useful when an operator is about to call
 * dply:install-runtime and isn't sure which key dply uses for a
 * given language (Node? node? nodejs?), or what version the wizard
 * would have picked.
 */
class ListRuntimesCommand extends Command
{
    protected $signature = 'dply:list-runtimes
        {--with-usage : Include site count per runtime from the fleet}
        {--json : Output as JSON}';

    protected $description = 'List runtimes dply manages and the canonical recent versions used by wizard presets.';

    /**
     * Recommended versions used by the polyglot preset. Mirrors the
     * ServerCreatePresetCatalog's polyglot runtime_defaults so the
     * "recommended" column stays a single source of truth.
     */
    private const RECOMMENDED = [
        'node' => '22',
        'python' => '3.12',
        'ruby' => '3.3',
        'go' => '1.22',
    ];

    public function handle(): int
    {
        $supported = MiseInstallScriptBuilder::SUPPORTED_RUNTIMES;
        $withUsage = (bool) $this->option('with-usage');
        $usage = $withUsage ? $this->collectUsage() : [];

        // PHP gets its own row because the polyglot preset includes it
        // and the install path differs (ondrej/php apt). Doesn't appear
        // in MiseInstallScriptBuilder's set.
        $rows = [['php', '8.4', 'ondrej/php apt', $usage['php'] ?? null]];
        foreach ($supported as $runtime) {
            $rows[] = [
                $runtime,
                self::RECOMMENDED[$runtime] ?? '—',
                'mise',
                $usage[$runtime] ?? null,
            ];
        }
        // 'static' isn't a runtime in the install sense but sites can use it.
        if ($withUsage && ($usage['static'] ?? 0) > 0) {
            $rows[] = ['static', '—', '<fg=gray>(no install)</>', $usage['static']];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'with_usage' => $withUsage,
                'runtimes' => array_map(function (array $row) {
                    $out = [
                        'runtime' => $row[0],
                        'recommended_version' => $row[1],
                        'install_path' => strip_tags($row[2]),
                    ];
                    if ($row[3] !== null) {
                        $out['site_count'] = $row[3];
                    }

                    return $out;
                }, $rows),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Runtimes managed by dply</>');
        $this->newLine();
        $headers = $withUsage
            ? ['runtime', 'recommended', 'install path', 'sites in fleet']
            : ['runtime', 'recommended', 'install path'];
        $tableRows = array_map(function (array $row) use ($withUsage) {
            return $withUsage
                ? [$row[0], $row[1], $row[2], $row[3] !== null ? (string) $row[3] : '—']
                : [$row[0], $row[1], $row[2]];
        }, $rows);
        $this->table($headers, $tableRows);
        $this->newLine();
        $this->line('<fg=gray>Use </><fg=white>dply:install-runtime &lt;server&gt; &lt;runtime&gt; &lt;version&gt;</><fg=gray> to install on a server.</>');

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function collectUsage(): array
    {
        return Site::query()
            ->selectRaw('runtime, COUNT(*) as count')
            ->whereNotNull('runtime')
            ->groupBy('runtime')
            ->pluck('count', 'runtime')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
