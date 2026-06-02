<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerDatabase;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Enable or disable remote network access for a single database.
 *
 * Postgres: upserts a pg_hba.conf rule scoped to the database name and ensures
 *   listen_addresses = '*'. Uses `systemctl reload postgresql` on disable (no
 *   full restart needed just to remove a rule).
 *
 * MySQL/MariaDB: flips bind-address = 0.0.0.0 and issues a targeted GRANT or
 *   REVOKE for the database user from the given CIDR.
 *
 * A tagged ServerFirewallRule is created/removed to open/close the engine port
 * in UFW. The tag is unique per server+engine so all databases on the same
 * engine share one rule — the rule is only removed when no databases on that
 * engine have remote_access = true.
 */
class ToggleDatabaseNetworkingJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public int $backoff = 10;

    public function __construct(
        public string $serverDatabaseId,
        public bool $enable,
        public string $allowedCidr,
        public ?string $userId = null,
    ) {
        $q = config('server_database.install_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        ServerFirewallProvisioner $firewall,
    ): void {
        /** @var ServerDatabase|null $db */
        $db = ServerDatabase::query()->with('server')->find($this->serverDatabaseId);
        if (! $db || ! $db->server) {
            return;
        }

        $script = $this->enable
            ? DatabaseEngineInstallScripts::enableDatabaseRemoteAccessScript(
                $db->engine,
                $db->name,
                (string) ($db->username ?? ''),
                $this->allowedCidr,
            )
            : DatabaseEngineInstallScripts::disableDatabaseRemoteAccessScript(
                $db->engine,
                $db->name,
                (string) ($db->username ?? ''),
            );

        try {
            $output = $executor->runInlineBash(
                $db->server,
                'database:networking:'.$db->engine.':'.$db->name,
                $script,
                timeoutSeconds: 120,
                asRoot: true,
            );
        } catch (\Throwable $e) {
            Log::warning('ToggleDatabaseNetworkingJob: SSH failed', [
                'db_id' => $this->serverDatabaseId,
                'error' => $e->getMessage(),
            ]);

            $db->update([
                'remote_access' => ! $this->enable,
                'allowed_from' => $this->enable ? null : $db->allowed_from,
            ]);

            throw $e; // Let the queue retry.
        }

        if ($output->exitCode !== 0) {
            $message = Str::limit(trim($output->buffer), 800) ?: 'Networking toggle failed.';

            Log::warning('ToggleDatabaseNetworkingJob: script failed', [
                'db_id' => $this->serverDatabaseId,
                'output' => $message,
            ]);

            $db->update([
                'remote_access' => ! $this->enable,
                'allowed_from' => $this->enable ? null : $db->allowed_from,
            ]);

            return;
        }

        $db->update([
            'remote_access' => $this->enable,
            'allowed_from' => $this->enable ? $this->allowedCidr : null,
        ]);

        $this->syncEngineFirewallRule($db, $firewall);
        // Hetzner cloud firewall is synced inside ServerFirewallProvisioner::applyRule.
    }

    private function syncEngineFirewallRule(ServerDatabase $db, ServerFirewallProvisioner $firewall): void
    {
        $server = $db->server;
        $tag = 'dply-db-network-'.$db->engine;

        $existing = ServerFirewallRule::query()
            ->where('server_id', $server->id)
            ->whereJsonContains('tags', $tag)
            ->first();

        $anyRemote = ServerDatabase::query()
            ->where('server_id', $server->id)
            ->where('engine', $db->engine)
            ->where('remote_access', true)
            ->exists();

        if (! $anyRemote) {
            if ($existing) {
                try {
                    $firewall->removeFromHost($server, $existing);
                } catch (\Throwable) {
                }
                $existing->delete();
            }

            return;
        }

        if ($existing) {
            return;
        }

        $port = ServerDatabaseEngine::query()
            ->where('server_id', $server->id)
            ->where('engine', $db->engine)
            ->value('port') ?? ServerDatabaseEngine::defaultPortFor($db->engine);

        $rule = ServerFirewallRule::query()->create([
            'server_id' => $server->id,
            'name' => sprintf('Database · %s network', ucfirst($db->engine)),
            'port' => (int) $port,
            'protocol' => 'tcp',
            'source' => 'any',
            'action' => 'allow',
            'enabled' => true,
            'sort_order' => (int) (ServerFirewallRule::query()->where('server_id', $server->id)->max('sort_order') ?? 0) + 1,
            'tags' => ['dply-database', $tag],
        ]);

        try {
            $firewall->applyRule($server, $rule);
        } catch (\Throwable) {
        }
    }
}
