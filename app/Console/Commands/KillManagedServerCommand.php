<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Servers\DeleteServerAction;
use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Beta abuse kill switch. Destroys a dply-managed VM (the free-CX22 grant is the
 * usual target) — tearing it down in whichever Hetzner project it lives in,
 * including the isolated beta project. Use when a box is mining crypto, sending
 * spam, or otherwise abusing the platform: stops the spend and the abuse.
 *
 *   php artisan dply:managed:kill <server-id>
 */
class KillManagedServerCommand extends Command
{
    protected $signature = 'dply:managed:kill {server : Server id} {--force : Skip confirmation}';

    protected $description = 'Destroy a dply-managed server (beta abuse kill switch).';

    public function handle(DeleteServerAction $deleteServer): int
    {
        $server = Server::find($this->argument('server'));

        if ($server === null) {
            $this->error('Server not found.');

            return self::FAILURE;
        }

        if (! $server->usesManagedHosting()) {
            $this->error("Server {$server->id} is not dply-managed — refusing to kill a BYO server.");

            return self::FAILURE;
        }

        $this->line("Server: {$server->name} ({$server->id}) — {$server->size} @ {$server->region}");
        $this->line("Org: {$server->organization?->name} ({$server->organization_id})");

        if (! $this->option('force') && ! $this->confirm('Destroy this managed server now? This is irreversible.')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $deleteServer->execute($server, null, ['reason' => 'beta_kill_switch'], 'beta_kill_switch');

        $this->info("Killed managed server {$server->id}.");

        return self::SUCCESS;
    }
}
