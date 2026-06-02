<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerDatabaseEngine;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ToggleDatabaseEngineRemoteAccessJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public int $backoff = 10;

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

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        ServerFirewallProvisioner $firewall,
    ): void {
        $row = ServerDatabaseEngine::query()->with('server')->find($this->serverDatabaseEngineId);
        if (! $row || ! $row->server) {
            return;
        }

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

            return;
        }

        $row->update([
            'remote_access' => $this->enable,
            'allowed_from' => $this->enable ? $this->allowedCidr : null,
        ]);

        $this->syncFirewallRule($row, $firewall);
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
                }
                $existing->delete();
            }

            return;
        }

        if ($existing) {
            if ($existing->source !== $this->allowedCidr) {
                $existing->update(['source' => $this->allowedCidr]);
                try {
                    $firewall->applyRule($server, $existing);
                } catch (\Throwable) {
                }
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

        try {
            $firewall->applyRule($server, $rule);
        } catch (\Throwable) {
        }
    }
}
