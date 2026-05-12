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

        // Multi-instance detection: if there's at least one other instance of
        // this engine on the same server, we tear down JUST this instance —
        // disable + remove the templated systemd unit, the per-instance config
        // file, and the per-instance data dir — and leave the apt package +
        // sibling instances intact. The `uninstallInstanceScript` builder
        // already handles both modes via `isLastInstance`.
        $isLastInstance = ! ServerCacheService::query()
            ->where('server_id', $row->server_id)
            ->where('engine', $engine)
            ->where('id', '!=', $row->id)
            ->exists();

        $row->update([
            'status' => ServerCacheService::STATUS_UNINSTALLING,
            'error_message' => null,
        ]);

        try {
            $output = $executor->runInlineBash(
                $row->server,
                'cache-service:uninstall:'.$engine.':'.$instanceName,
                CacheServiceInstallScripts::uninstallInstanceScript($engine, $instanceName, $isLastInstance),
                timeoutSeconds: 600,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Uninstall command failed.'
                );
            }

            // Capability cache invalidation is only needed when the package is
            // actually gone — sibling instances still expose the same engine.
            if ($isLastInstance) {
                $capabilities->forget($row->server);
            }

            // Audit BEFORE delete so the row id and engine are still resolvable on the audit
            // record (we capture the engine in meta).
            $serverForAudit = $row->server;
            $auditMeta = [
                'engine' => $engine,
                'instance' => $instanceName,
                'last_instance' => $isLastInstance,
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
                'last_instance' => $isLastInstance,
                'error' => Str::limit($e->getMessage(), 800),
            ]);
        }
    }
}
