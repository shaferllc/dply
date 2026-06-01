<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Enable or disable remote access for a database engine on a server.
 *
 * Enable path: writes listen_addresses / pg_hba (postgres) or bind-address
 *   (mysql/mariadb) over SSH, restarts the service, and creates a UFW-backed
 *   ServerFirewallRule for the engine's port from the given CIDR.
 *
 * Disable path: reverts to localhost-only binding, removes the dply-managed
 *   pg_hba rule, and deletes the tagged firewall rule.
 *
 * The engine row's `remote_access` + `allowed_from` fields are updated
 * optimistically before the job runs and confirmed/rolled-back on completion.
 */
class ToggleDatabaseEngineRemoteAccessJob implements ShouldQueue
{
    use Queueable;
    use WritesConsoleAction;

    public int $timeout = 300;

    public function __construct(
        public string $serverDatabaseEngineId,
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
        return ServerDatabaseEngine::query()->findOrFail($this->serverDatabaseEngineId);
    }

    protected function consoleKind(): string
    {
        return 'db_engine_remote_access';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        ServerFirewallProvisioner $firewall,
    ): void {
        /** @var ServerDatabaseEngine|null $row */
        $row = ServerDatabaseEngine::query()->with('server')->find($this->serverDatabaseEngineId);
        if (! $row) {
            return;
        }

        $emit = $this->beginConsoleAction();

        $action = $this->enable ? 'Enabling' : 'Disabling';
        $emit->step('db', __(':action remote access for :engine …', [
            'action' => $action,
            'engine' => $row->engine,
        ]));

        try {
            $script = $this->enable
                ? DatabaseEngineInstallScripts::enableRemoteAccessScript($row->engine, $this->allowedCidr)
                : DatabaseEngineInstallScripts::disableRemoteAccessScript($row->engine);

            $output = $executor->runInlineBashWithOutputCallback(
                $row->server,
                'database-engine:remote-access:'.$row->engine,
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
                    Str::limit(trim($output->buffer), 800) ?: 'Remote access toggle failed.'
                );
            }

            $this->syncFirewallRule($row, $firewall);

            $row->update([
                'remote_access' => $this->enable,
                'allowed_from' => $this->enable ? $this->allowedCidr : null,
            ]);

            $label = $this->enable
                ? __('Remote access enabled from :cidr.', ['cidr' => $this->allowedCidr])
                : __('Remote access disabled — engine is localhost-only.');

            $emit->success('db', $label);
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $message = Str::limit($e->getMessage(), 800);

            // Roll back optimistic flag so the UI reflects the real state.
            $row->update([
                'remote_access' => ! $this->enable,
                'allowed_from' => $this->enable ? null : $row->allowed_from,
            ]);

            $emit->error('db', $message);
            $this->failConsoleAction($message);
        }
    }

    private function syncFirewallRule(ServerDatabaseEngine $row, ServerFirewallProvisioner $firewall): void
    {
        $tag = 'dply-db-remote-'.$row->engine;
        $server = $row->server;

        $existing = ServerFirewallRule::query()
            ->where('server_id', $server->id)
            ->whereJsonContains('tags', $tag)
            ->first();

        if (! $this->enable) {
            if ($existing) {
                try {
                    $firewall->removeFromHost($server, $existing);
                } catch (\Throwable) {
                    // Best-effort — the rule row is deleted regardless.
                }
                $existing->delete();
            }

            return;
        }

        if ($existing) {
            // Update the source CIDR if it changed.
            if ($existing->source !== $this->allowedCidr) {
                $before = $existing->replicate();
                $existing->update(['source' => $this->allowedCidr]);
                $firewall->applyRule($server, $existing, $before);
            }

            return;
        }

        $rule = ServerFirewallRule::query()->create([
            'server_id' => $server->id,
            'name' => sprintf('Database · %s remote', ucfirst($row->engine)),
            'port' => (int) $row->port,
            'protocol' => 'tcp',
            'source' => $this->allowedCidr,
            'action' => 'allow',
            'enabled' => true,
            'sort_order' => (int) (ServerFirewallRule::query()->where('server_id', $server->id)->max('sort_order') ?? 0) + 1,
            'tags' => ['dply-database', $tag],
        ]);

        $firewall->applyRule($server, $rule);
    }
}
