<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\ServerDatabase;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
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
    use WritesConsoleAction;

    public int $timeout = 300;

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

    protected function consoleSubject(): Model
    {
        return ServerDatabase::query()->with('server')->findOrFail($this->serverDatabaseId);
    }

    protected function consoleKind(): string
    {
        return 'db_networking';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        ServerFirewallProvisioner $firewall,
    ): void {
        /** @var ServerDatabase|null $db */
        $db = ServerDatabase::query()->with('server')->find($this->serverDatabaseId);
        if (! $db) {
            return;
        }

        $emit = $this->beginConsoleAction();
        $action = $this->enable ? 'Enabling' : 'Disabling';

        $emit->step('db', __(':action remote access for database :name …', [
            'action' => $action,
            'name' => $db->name,
        ]));

        try {
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

            $output = $executor->runInlineBashWithOutputCallback(
                $db->server,
                'database:networking:'.$db->engine.':'.$db->name,
                $script,
                function (string $type, string $chunk) use ($emit): void {
                    foreach (preg_split("/\r?\n/", $chunk) ?: [] as $line) {
                        $line = trim($line);
                        if ($line !== '') {
                            $emit($line, ConsoleAction::LEVEL_INFO, 'ssh');
                        }
                    }
                },
                timeoutSeconds: 120,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Networking toggle failed.'
                );
            }

            $db->update([
                'remote_access' => $this->enable,
                'allowed_from' => $this->enable ? $this->allowedCidr : null,
            ]);

            $this->syncEngineFirewallRule($db, $firewall);

            $label = $this->enable
                ? __('Remote access enabled for :name from :cidr.', ['name' => $db->name, 'cidr' => $this->allowedCidr])
                : __('Remote access disabled for :name.', ['name' => $db->name]);

            $emit->success('db', $label);
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $message = Str::limit($e->getMessage(), 800);

            // Roll back optimistic flag.
            $db->update([
                'remote_access' => ! $this->enable,
                'allowed_from' => $this->enable ? null : $db->allowed_from,
            ]);

            $emit->error('db', $message);
            $this->failConsoleAction($message);
        }
    }

    /**
     * Manages a single UFW rule per engine on this server. The rule stays open
     * as long as at least one database on the engine has remote_access = true.
     * When the last database disables remote access, the rule is removed.
     */
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
                    // Best-effort.
                }
                $existing->delete();
            }

            return;
        }

        $port = \App\Models\ServerDatabaseEngine::query()
            ->where('server_id', $server->id)
            ->where('engine', $db->engine)
            ->value('port') ?? \App\Models\ServerDatabaseEngine::defaultPortFor($db->engine);

        if ($existing) {
            return; // Rule already open for this engine — no change needed.
        }

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
            // Best-effort — the row is saved; operator can reconcile from Firewall tab.
        }
    }
}
