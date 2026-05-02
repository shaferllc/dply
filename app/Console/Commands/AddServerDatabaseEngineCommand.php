<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Servers\AttachDatabaseEngineToServer;
use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Register a database engine as installed on a server.
 *
 *   dply:server:add-engine <server> <engine> [--engine-version=] [--default]
 *
 * Data-only — the engine package itself must be installed via apt
 * before the engine becomes useful (the action's docblock spells
 * this out). Once registered, sites on the server can target the
 * engine via the engine picker in site-create or by setting
 * Site.database_engine directly.
 *
 * The first engine registered on a server becomes the default
 * automatically (so a server always has a default while it has at
 * least one engine). Pass --default to override an existing default
 * with the engine being added.
 */
class AddServerDatabaseEngineCommand extends Command
{
    protected $signature = 'dply:server:add-engine
        {server : Server ID, name, or IP}
        {engine : Engine key (postgres / mysql84 / mariadb / etc.)}
        {--engine-version= : Engine version (e.g. 17, 8.4)}
        {--default : Mark this engine as the server\'s default}';

    protected $description = 'Register a database engine as installed on a server.';

    public function handle(AttachDatabaseEngineToServer $action): int
    {
        $server = $this->resolveServer((string) $this->argument('server'));
        if ($server === null) {
            $this->error('Server not found: '.$this->argument('server'));

            return self::FAILURE;
        }

        $engine = (string) $this->argument('engine');
        $version = $this->option('engine-version');
        $version = is_string($version) && trim($version) !== '' ? trim($version) : null;
        $isDefault = (bool) $this->option('default');

        try {
            $row = $action->execute($server, $engine, $version, $isDefault);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s %s on %s%s.',
            $row->wasRecentlyCreated ? 'Registered' : 'Updated',
            $row->engine.($row->version ? ' '.$row->version : ''),
            $server->name,
            $row->is_default ? ' (default)' : '',
        ));

        return self::SUCCESS;
    }

    private function resolveServer(string $needle): ?Server
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Server::query()
            ->where('id', $needle)
            ->orWhere('name', $needle)
            ->orWhere('ip_address', $needle)
            ->first();
    }
}
