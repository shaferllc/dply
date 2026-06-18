<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Servers\DetachDatabaseEngineFromServer;
use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Unregister a database engine from a server.
 *
 *   dply:server:remove-engine <server> <engine>
 *
 * Refuses when sites on the server still pin the engine via their
 * database_engine column — surfaces the conflicting site names so
 * the operator can re-pin them first. Use --force to override the
 * check (sites silently fall back to the server's new default
 * engine, which may not be what they want).
 */
class RemoveServerDatabaseEngineCommand extends Command
{
    protected $signature = 'dply:server:remove-engine
        {server : Server ID, name, or IP}
        {engine : Engine key to remove (postgres / mysql84 / mariadb / etc.)}';

    protected $description = 'Unregister a database engine from a server.';

    public function handle(DetachDatabaseEngineFromServer $action): int
    {
        $server = $this->resolveServer((string) $this->argument('server'));
        if ($server === null) {
            $this->error('Server not found: '.$this->argument('server'));

            return self::FAILURE;
        }

        $engine = (string) $this->argument('engine');
        $result = $action->execute($server, $engine);

        if (! $result['ok']) {
            $this->error(sprintf(
                'Cannot remove %s — these sites still target it: %s',
                $engine,
                implode(', ', $result['sites_using_engine']),
            ));
            $this->line('Re-pin those sites to a different engine before retrying.');

            return self::FAILURE;
        }

        $this->info(sprintf('Unregistered %s on %s.', $engine, $server->name));

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
