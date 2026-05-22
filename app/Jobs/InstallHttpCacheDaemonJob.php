<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\HttpCacheDaemonInstallScripts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Sibling to {@see InstallCacheServiceJob}, but for HTTP-front cache daemons
 * (Varnish today) — daemons that own port 80 and require the backend webserver
 * to be reconfigured to listen on 127.0.0.1:8080.
 *
 * For v1 the backend-port flip is recorded as a follow-up step the operator
 * handles via the Caching workspace (or the v2 auto-flip path); this job
 * focuses on the daemon install + VCL deploy.
 */
class InstallHttpCacheDaemonJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(
        public string $serverCacheServiceId,
        public int $backendPort = 8080,
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

        $row->update([
            'status' => ServerCacheService::STATUS_INSTALLING,
            'error_message' => null,
            'install_output' => '',
        ]);

        try {
            $script = HttpCacheDaemonInstallScripts::installScriptForRow($row, $this->backendPort)
                ."\n".HttpCacheDaemonInstallScripts::versionProbeScript($row->engine);

            $bufferAcc = '';
            $lastFlush = 0.0;
            $flush = function (bool $force = false) use ($row, &$bufferAcc, &$lastFlush): void {
                $now = microtime(true);
                if (! $force && ($now - $lastFlush) < 3.0) {
                    return;
                }
                $lastFlush = $now;
                $row->update(['install_output' => mb_substr($bufferAcc, -32_000)]);
            };

            $output = $executor->runInlineBashWithOutputCallback(
                $row->server,
                'http-cache-daemon:install:'.$row->engine,
                $script,
                function (string $type, string $chunk) use (&$bufferAcc, $flush): void {
                    $bufferAcc .= $chunk;
                    $flush();
                },
                timeoutSeconds: 600,
                asRoot: true,
            );
            $flush(true);

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Install command failed.'
                );
            }

            $version = CacheServiceInstallScripts::parseVersionFromBuffer($output->buffer);

            $row->update([
                'status' => ServerCacheService::STATUS_RUNNING,
                'version' => $version,
            ]);

            $audit->record($row->server, ServerCacheServiceAuditEvent::EVENT_INSTALLED, [
                'engine' => $row->engine,
                'version' => $version,
                'port' => $row->port,
                'backend_port' => $this->backendPort,
                'family' => ServerCacheService::FAMILY_HTTP_FRONT,
            ]);
        } catch (\Throwable $e) {
            $row->update([
                'status' => ServerCacheService::STATUS_FAILED,
                'error_message' => Str::limit($e->getMessage(), 800),
            ]);

            $audit->record($row->server, ServerCacheServiceAuditEvent::EVENT_INSTALL_FAILED, [
                'engine' => $row->engine,
                'family' => ServerCacheService::FAMILY_HTTP_FRONT,
                'error' => Str::limit($e->getMessage(), 800),
            ]);
        }
    }
}
