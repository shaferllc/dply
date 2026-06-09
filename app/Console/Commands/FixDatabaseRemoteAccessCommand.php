<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServerDatabase;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Console\Command;

/**
 * Emergency command to apply remote-access config directly over SSH without
 * going through the queue — useful when Horizon isn't processing or jobs fail.
 *
 * Usage: php artisan dply:db-remote-access {database_id} {cidr}
 */
class FixDatabaseRemoteAccessCommand extends Command
{
    protected $signature = 'dply:db-remote-access
        {database_id : The ServerDatabase ULID}
        {cidr=0.0.0.0/0 : The allowed CIDR}
        {--disable : Disable remote access instead of enabling}';

    protected $description = 'Apply (or remove) remote-access config for a database directly over SSH.';

    public function handle(ExecuteRemoteTaskOnServer $executor): int
    {
        $db = ServerDatabase::query()->with('server')->find($this->argument('database_id'));

        if (! $db) {
            $this->error('Database not found: '.$this->argument('database_id'));

            return self::FAILURE;
        }

        $enable = ! $this->option('disable');
        $cidr = (string) $this->argument('cidr');

        $this->line('Server: '.$db->server->name.' ('.$db->server->ip_address.')');
        $this->line('Database: '.$db->name.' ('.$db->engine.')');
        $this->line('Action: '.($enable ? "enable (CIDR: $cidr)" : 'disable'));

        $script = $enable
            ? DatabaseEngineInstallScripts::enableDatabaseRemoteAccessScript(
                $db->engine,
                $db->name,
                (string) $db->username,
                $cidr,
            )
            : DatabaseEngineInstallScripts::disableDatabaseRemoteAccessScript(
                $db->engine,
                $db->name,
                (string) $db->username,
            );

        $this->line('Running SSH script…');

        $output = $executor->runInlineBash(
            $db->server,
            'dply:db-remote-access:manual',
            $script,
            timeoutSeconds: 120,
            asRoot: true,
        );

        $this->line($output->buffer);

        if ($output->exitCode !== 0) {
            $this->error('Script failed with exit code '.$output->exitCode);

            return self::FAILURE;
        }

        $db->update([
            'remote_access' => $enable,
            'allowed_from' => $enable ? $cidr : null,
        ]);

        $this->info('Done. Database updated: remote_access='.($enable ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
