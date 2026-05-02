<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Rename a server's display name.
 *
 *   dply:server:rename <server> <new-name> [--dry-run] [--json]
 *
 * Display-only — does not change the hostname or IP. Useful when
 * the dashboard name has drifted from a re-purposed server's
 * actual role ("web-1" is now hosting Postgres, etc.).
 */
class RenameServerCommand extends Command
{
    protected $signature = 'dply:server:rename
        {server : Server ID, name, or IP}
        {new-name : New display name}
        {--dry-run : Report the proposed change without writing}
        {--json : Output as JSON}';

    protected $description = 'Rename a server\'s display name.';

    public function handle(): int
    {
        $needle = (string) $this->argument('server');
        $server = $this->resolveServer($needle);
        if ($server === null) {
            $this->error("Server not found: {$needle}");

            return self::FAILURE;
        }

        $newName = trim((string) $this->argument('new-name'));
        if ($newName === '') {
            $this->error('New name cannot be empty.');

            return self::FAILURE;
        }
        if ($newName === $server->name) {
            $this->info('Server already has that name.');

            return self::SUCCESS;
        }

        $oldName = $server->name;
        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun) {
            $server->name = $newName;
            $server->save();
        }

        $payload = [
            'server_id' => $server->id,
            'dry_run' => $dryRun,
            'from' => $oldName,
            'to' => $newName,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $verb = $dryRun ? 'Would rename' : 'Renamed';
        $this->info("{$verb} server: {$oldName} → {$newName}");

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
