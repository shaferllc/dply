<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerDatabase;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerFirewallRule;
use App\Models\User;
use App\Services\Notifications\ServerNetworkingNotificationDispatcher;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Support\Servers\DatabaseEngineInstallScripts;
use App\Support\Servers\DedicatedCacheServerProvisionConfig;
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
        ServerNetworkingNotificationDispatcher $notifications,
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

        $notifications->notify(
            $db->server,
            $this->enable ? 'db_access_enabled' : 'db_access_disabled',
            [(string) $db->name],
            $this->userId ? User::query()->find($this->userId) : null,
            ['database_id' => $db->id, 'engine' => $db->engine, 'allowed_from' => $this->enable ? $this->allowedCidr : null],
        );
    }

    /**
     * Reconcile the engine's UFW rules so the database port is opened ONLY to the
     * trusted networks listed in each remote-access database's `allowed_from` —
     * one rule per distinct CIDR, never source=any. The port being world-open is
     * what abuse scanners flag (pg_hba.conf only gates auth, not the open port),
     * so legacy source=any rules are torn down here too.
     */
    private function syncEngineFirewallRule(ServerDatabase $db, ServerFirewallProvisioner $firewall): void
    {
        $server = $db->server;
        $tag = 'dply-db-network-'.$db->engine;

        /** @var \Illuminate\Support\Collection<int, ServerFirewallRule> $existing */
        $existing = ServerFirewallRule::query()
            ->where('server_id', $server->id)
            ->whereJsonContains('tags', $tag)
            ->get();

        // Union of every remote-access database's allowed_from CIDRs on this
        // engine. Each becomes its own scoped UFW rule.
        $desiredSources = ServerDatabase::query()
            ->where('server_id', $server->id)
            ->where('engine', $db->engine)
            ->where('remote_access', true)
            ->get()
            ->flatMap(fn (ServerDatabase $d): array => DedicatedCacheServerProvisionConfig::splitAllowedFrom((string) $d->allowed_from))
            ->map(fn (string $cidr): string => trim($cidr))
            ->filter(fn (string $cidr): bool => $cidr !== '')
            ->unique()
            ->values();

        // Drop any host rule whose source is no longer wanted — including legacy
        // source=any rules that exposed the port to the whole internet.
        $keptSources = [];
        foreach ($existing as $rule) {
            if ($desiredSources->contains((string) $rule->source)) {
                $keptSources[] = (string) $rule->source;

                continue;
            }

            try {
                $firewall->removeFromHost($server, $rule);
            } catch (\Throwable) {
            }
            $rule->delete();
        }

        if ($desiredSources->isEmpty()) {
            return;
        }

        $port = ServerDatabaseEngine::query()
            ->where('server_id', $server->id)
            ->where('engine', $db->engine)
            ->value('port') ?? ServerDatabaseEngine::defaultPortFor($db->engine);

        foreach ($desiredSources as $source) {
            if (in_array($source, $keptSources, true)) {
                continue;
            }

            $rule = ServerFirewallRule::query()->create([
                'server_id' => $server->id,
                'name' => sprintf('Database · %s network', ucfirst($db->engine)),
                'port' => (int) $port,
                'protocol' => 'tcp',
                'source' => $source,
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
}
