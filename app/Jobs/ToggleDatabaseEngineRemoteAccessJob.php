<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ManagedFirewallPort;
use App\Support\Servers\DatabaseEngineInstallScripts;
use App\Support\Servers\ServerNetworkPeers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ToggleDatabaseEngineRemoteAccessJob implements ShouldQueue
{
    use Queueable;
    use WritesConsoleAction;

    public int $timeout = 300;

    public int $tries = 2;

    public int $backoff = 10;

    /**
     * @param  list<string>  $peerServerIds  Servers picked from the network to admit.
     *                                       When non-empty each gets its own /32 rule and
     *                                       $allowedCidr is only the recorded summary;
     *                                       when empty $allowedCidr is opened as a single
     *                                       rule (the hand-typed path).
     */
    public function __construct(
        public string $serverDatabaseEngineId,
        public bool $enable,
        public string $allowedCidr,
        public ?string $userId = null,
        public array $peerServerIds = [],
        public array $extraCidrs = [],
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
        ManagedFirewallPort $ports,
    ): void {
        $row = ServerDatabaseEngine::query()->with('server')->find($this->serverDatabaseEngineId);
        if (! $row || ! $row->server) {
            return;
        }

        // The component seeds a QUEUED ConsoleAction so the banner appears the
        // instant the button is clicked, but nothing here ever moved it on — so a
        // job that ran fine still read "Queued — waiting for worker" until the
        // 10-minute stale sweeper dismissed it. Drive it like the install jobs do.
        $emit = $this->beginConsoleAction();
        $emit(
            $this->enable
                ? 'Enabling remote access for '.$row->engine.' …'
                : 'Disabling remote access for '.$row->engine.' …',
            ConsoleAction::LEVEL_INFO,
            'remote-access',
        );

        $script = $this->enable
            ? DatabaseEngineInstallScripts::enableRemoteAccessScript($row->engine, $this->allowedCidr)
            : DatabaseEngineInstallScripts::disableRemoteAccessScript($row->engine);

        try {
            $output = $executor->runInlineBash(
                $row->server,
                'database-engine:remote-access:'.$row->engine,
                $script,
                timeoutSeconds: 120,
                asRoot: true,
            );
        } catch (\Throwable $e) {
            Log::warning('ToggleDatabaseEngineRemoteAccessJob: SSH failed', [
                'engine_id' => $this->serverDatabaseEngineId,
                'error' => $e->getMessage(),
            ]);

            $row->update([
                'remote_access' => ! $this->enable,
                'allowed_from' => $this->enable ? null : $row->allowed_from,
            ]);

            $this->failConsoleAction($e->getMessage());

            throw $e;
        }

        if ($output->exitCode !== 0) {
            Log::warning('ToggleDatabaseEngineRemoteAccessJob: script failed', [
                'engine_id' => $this->serverDatabaseEngineId,
                'output' => Str::limit(trim($output->buffer), 500),
            ]);

            $row->update([
                'remote_access' => ! $this->enable,
                'allowed_from' => $this->enable ? null : $row->allowed_from,
            ]);

            $this->failConsoleAction(Str::limit(trim($output->buffer), 500) ?: 'The remote-access script failed on the server.');

            return;
        }

        $row->update([
            'remote_access' => $this->enable,
            'allowed_from' => $this->enable ? $this->allowedCidr : null,
        ]);

        $this->syncFirewallRule($row, $ports);

        $emit(
            $this->enable
                ? 'Remote access enabled — '.($row->allowed_from ?: 'no source recorded')
                : 'Remote access disabled — port closed.',
            ConsoleAction::LEVEL_INFO,
            'remote-access',
        );

        $this->completeConsoleAction();
    }

    /**
     * Keep the managed UFW rule in step with the engine's remote-access state.
     * The reconciliation this used to do inline now lives in
     * {@see ManagedFirewallPort}, shared with the dply Logs aggregator.
     */
    private function syncFirewallRule(ServerDatabaseEngine $row, ManagedFirewallPort $ports): void
    {
        $server = $row->server;
        $tag = 'dply-db-remote-'.$row->engine;
        $port = (int) $row->port;
        $engineLabel = ucfirst($row->engine);

        if (! $this->enable) {
            // Close both shapes: the selection may have been made either way, and
            // a stale rule from the other path would keep the port open.
            $ports->close($server, $tag);
            $ports->closeAll($server, $tag);

            return;
        }

        if ($this->peerServerIds !== [] || $this->extraCidrs !== []) {
            // One /32 per selected server. A covering CIDR would be shorter and
            // would also admit every other host in that range.
            $peers = Server::query()
                ->whereIn('id', $this->peerServerIds)
                ->get()
                ->mapWithKeys(function (Server $peer) {
                    $cidr = ServerNetworkPeers::hostCidr($peer);

                    return $cidr === null ? [] : [$peer->id => $cidr];
                })
                ->all();

            $names = Server::query()
                ->whereIn('id', array_keys($peers))
                ->pluck('name', 'id')
                ->all();

            // Hand-typed sources join the same group rather than replacing it —
            // admitting a laptop should not revoke the app server. Keyed by the
            // CIDR itself so the key is stable across reconciles.
            foreach ($this->extraCidrs as $cidr) {
                $cidr = trim((string) $cidr);
                if ($cidr !== '') {
                    $peers['cidr:'.$cidr] = $cidr;
                }
            }

            // A pre-group single rule may be left over. close() cannot be used:
            // group members carry the group tag too, so it would delete one of
            // them at random. closeUngrouped() targets only the non-member.
            $ports->closeUngrouped($server, $tag);

            $ports->openGroup(
                server: $server,
                groupTag: $tag,
                port: $port,
                sourcesByKey: $peers,
                nameFor: fn (string $key, string $cidr): string => sprintf(
                    'Database · %s remote · %s',
                    $engineLabel,
                    $names[$key] ?? $cidr,
                ),
                extraTags: ['dply-database'],
            );

            return;
        }

        // Hand-typed CIDR: a group rule set may be left over from the picker.
        $ports->closeAll($server, $tag);

        $ports->open(
            server: $server,
            tag: $tag,
            port: $port,
            source: $this->allowedCidr,
            name: sprintf('Database · %s remote', $engineLabel),
            extraTags: ['dply-database'],
        );
    }
}
