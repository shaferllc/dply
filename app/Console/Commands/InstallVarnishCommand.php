<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\InstallHttpCacheDaemonJob;
use App\Jobs\UninstallHttpCacheDaemonJob;
use App\Models\Server;
use App\Models\ServerCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * CLI surface for the Varnish (HTTP-front) install flow. v1 ships without a
 * dedicated server-workspace tab — the existing `WorkspaceCaches.php`
 * hardcodes redis-family assumptions and shoehorning Varnish there would
 * regress object-cache flows. This command is the install trigger until
 * the v2 workspace tab lands.
 */
class InstallVarnishCommand extends Command
{
    protected $signature = 'dply:cache:varnish
        {server : Server ID or name}
        {--uninstall : Uninstall instead of install}
        {--backend-port=8080 : Port the backend webserver should bind to}';

    protected $description = 'Install or uninstall the Varnish HTTP-front cache daemon on a server.';

    public function handle(): int
    {
        $serverArg = (string) $this->argument('server');
        $server = Server::query()
            ->where('id', $serverArg)
            ->orWhere('name', $serverArg)
            ->first();

        if ($server === null) {
            $this->error("Server not found: {$serverArg}");

            return self::FAILURE;
        }

        $existing = ServerCacheService::query()
            ->where('server_id', $server->id)
            ->where('engine', 'varnish')
            ->first();

        if ($this->option('uninstall')) {
            if ($existing === null) {
                $this->info('No Varnish row on this server — nothing to uninstall.');

                return self::SUCCESS;
            }
            UninstallHttpCacheDaemonJob::dispatch($existing->id);
            $this->info("Queued Varnish uninstall on server {$server->name}.");

            return self::SUCCESS;
        }

        if ($existing !== null) {
            $this->info("Varnish row already present (status={$existing->status}). Re-dispatching install — the script is idempotent.");
            InstallHttpCacheDaemonJob::dispatch($existing->id, (int) $this->option('backend-port'));

            return self::SUCCESS;
        }

        $row = ServerCacheService::query()->create([
            'id' => (string) Str::ulid(),
            'server_id' => $server->id,
            'engine' => 'varnish',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_PENDING,
            'port' => ServerCacheService::defaultPortFor('varnish'),
        ]);

        InstallHttpCacheDaemonJob::dispatch($row->id, (int) $this->option('backend-port'));
        $this->info("Queued Varnish install on server {$server->name} (row {$row->id}).");

        return self::SUCCESS;
    }
}
