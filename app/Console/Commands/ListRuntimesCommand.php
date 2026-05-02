<?php

declare(strict_types=1);

namespace App\Console\Commands;

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

        // PHP gets its own row because the polyglot preset includes it
        // and the install path differs (ondrej/php apt). Doesn't appear
        // in MiseInstallScriptBuilder's set.
        $rows = [['php', '8.4', 'ondrej/php apt']];
        foreach ($supported as $runtime) {
            $rows[] = [
                $runtime,
                self::RECOMMENDED[$runtime] ?? '—',
                'mise',
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'runtimes' => array_map(fn (array $row) => [
                    'runtime' => $row[0],
                    'recommended_version' => $row[1],
                    'install_path' => $row[2],
                ], $rows),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Runtimes managed by dply</>');
        $this->newLine();
        $this->table(['runtime', 'recommended', 'install path'], $rows);
        $this->newLine();
        $this->line('<fg=gray>Use </><fg=white>dply:install-runtime &lt;server&gt; &lt;runtime&gt; &lt;version&gt;</><fg=gray> to install on a server.</>');

        return self::SUCCESS;
    }
}
