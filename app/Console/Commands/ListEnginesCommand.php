<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Catalog of database engines dply knows how to install via apt.
 *
 *   dply:list-engines [--json]
 *
 * Sister command to dply:list-runtimes — exposes the engine ids
 * accepted by dply:server:add-engine alongside the apt package
 * dply will install when --install is passed.
 */
class ListEnginesCommand extends Command
{
    protected $signature = 'dply:list-engines
        {--json : Output as JSON}';

    protected $description = 'List database engine ids dply manages, with their apt package(s).';

    /**
     * Engine id → human label / apt package summary. Mirrors the
     * server_provision_options config and the
     * ServerProvisionCommandBuilder::installDatabaseIfNeeded mapping.
     */
    private const CATALOG = [
        ['postgres18', 'PostgreSQL 18', 'postgresql-18 (apt.postgresql.org)'],
        ['postgres17', 'PostgreSQL 17', 'postgresql-17 (apt.postgresql.org)'],
        ['postgres16', 'PostgreSQL 16', 'postgresql-16 (apt.postgresql.org)'],
        ['postgres15', 'PostgreSQL 15', 'postgresql-15 (apt.postgresql.org)'],
        ['postgres14', 'PostgreSQL 14', 'postgresql-14 (apt.postgresql.org)'],
        ['mysql84', 'MySQL 8.4 (LTS)', 'mysql-server (distro)'],
        ['mysql80', 'MySQL 8.0', 'mysql-server (distro)'],
        ['mysql57', 'MySQL 5.7 (legacy)', 'mysql-server (distro)'],
        ['mariadb114', 'MariaDB 11.4', 'mariadb-server (distro)'],
        ['mariadb11', 'MariaDB 11', 'mariadb-server (distro)'],
        ['mariadb1011', 'MariaDB 10.11 (LTS)', 'mariadb-server (distro)'],
        ['sqlite3', 'SQLite 3', 'sqlite3 + libsqlite3-0'],
    ];

    public function handle(): int
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'engines' => array_map(fn (array $row) => [
                    'engine' => $row[0],
                    'label' => $row[1],
                    'package' => $row[2],
                ], self::CATALOG),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Database engines managed by dply</>');
        $this->newLine();
        $this->table(['engine', 'label', 'package'], self::CATALOG);
        $this->newLine();
        $this->line('<fg=gray>Use </><fg=white>dply:server:add-engine &lt;server&gt; &lt;engine&gt; --install</><fg=gray> to install + register on a server.</>');

        return self::SUCCESS;
    }
}
