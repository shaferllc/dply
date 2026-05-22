<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class UninstallCacheServiceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(
        public string $serverCacheServiceId
    ) {
        $q = config('server_cache.install_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        ServerCacheServiceHostCapabilities $capabilities,
        CacheServiceAuditLogger $audit,
    ): void {
        /** @var ServerCacheService|null $row */
        $row = ServerCacheService::query()->with('server')->find($this->serverCacheServiceId);
        if (! $row) {
            return;
        }

        $engine = $row->engine;
        $instanceName = $row->name;

        // One row per (server, engine) — uninstall is always a full `apt purge`. The legacy
        // sibling-instance branching is gone; if a future operator wants the package retained
        // they'd use the engine-switch flow rather than uninstall + reinstall.
        $row->update([
            'status' => ServerCacheService::STATUS_UNINSTALLING,
            'error_message' => null,
        ]);

        try {
            $output = $executor->runInlineBash(
                $row->server,
                'cache-service:uninstall:'.$engine.':'.$instanceName,
                CacheServiceInstallScripts::uninstallScript($engine),
                timeoutSeconds: 600,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Uninstall command failed.'
                );
            }

            $capabilities->forget($row->server);

            // Audit BEFORE delete so the server reference + engine name are still resolvable —
            // post-delete the relation reload would 404.
            $serverForAudit = $row->server;
            $auditMeta = [
                'engine' => $engine,
                'instance' => $instanceName,
            ];
            $row->delete();

            $audit->record($serverForAudit, ServerCacheServiceAuditEvent::EVENT_UNINSTALLED, $auditMeta);
        } catch (\Throwable $e) {
            $row->update([
                'status' => ServerCacheService::STATUS_FAILED,
                'error_message' => Str::limit($e->getMessage(), 800),
            ]);

            $audit->record($row->server, ServerCacheServiceAuditEvent::EVENT_UNINSTALL_FAILED, [
                'engine' => $engine,
                'instance' => $instanceName,
                'error' => Str::limit($e->getMessage(), 800),
            ]);
        }
    }
}
