<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\HttpCacheDaemonInstallScripts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class UninstallHttpCacheDaemonJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(
        public string $serverCacheServiceId,
    ) {
        $q = config('server_cache.install_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        CacheServiceAuditLogger $audit,
    ): void {
        /** @var ServerCacheService|null $row */
        $row = ServerCacheService::query()->with('server')->find($this->serverCacheServiceId);
        if ($row === null || ! ServerCacheService::isHttpFrontEngine($row->engine)) {
            return;
        }

        $engine = $row->engine;
        $serverForAudit = $row->server;
        $row->update(['status' => ServerCacheService::STATUS_UNINSTALLING]);

        try {
            $script = HttpCacheDaemonInstallScripts::uninstallScript($engine);
            $executor->runInlineBash(
                $row->server,
                'http-cache-daemon:uninstall:'.$engine,
                $script,
                timeoutSeconds: 300,
                asRoot: true,
            );

            $row->delete();

            $audit->record($serverForAudit, ServerCacheServiceAuditEvent::EVENT_UNINSTALLED, [
                'engine' => $engine,
                'family' => ServerCacheService::FAMILY_HTTP_FRONT,
            ]);
        } catch (\Throwable $e) {
            $row->update([
                'status' => ServerCacheService::STATUS_FAILED,
                'error_message' => Str::limit($e->getMessage(), 800),
            ]);

            $audit->record($serverForAudit, ServerCacheServiceAuditEvent::EVENT_UNINSTALL_FAILED, [
                'engine' => $engine,
                'family' => ServerCacheService::FAMILY_HTTP_FRONT,
                'error' => Str::limit($e->getMessage(), 800),
            ]);
        }
    }
}
