<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use App\Support\Servers\ServerResourcePreflight;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class InstallCacheServiceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900; // apt + systemd warmup can take a few minutes on small boxes

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
        ServerResourcePreflight $preflight,
    ): void {
        /** @var ServerCacheService|null $row */
        $row = ServerCacheService::query()->with('server')->find($this->serverCacheServiceId);
        if (! $row) {
            return;
        }

        $row->update([
            'status' => ServerCacheService::STATUS_INSTALLING,
            'error_message' => null,
        ]);

        // Resource preflight — bail BEFORE running any apt commands so a too-small box doesn't
        // OOM-kill mid-install. The check itself is one cheap SSH round-trip.
        $preflightResult = $preflight->check(
            $row->server,
            ServerResourcePreflight::requirementsForCacheEngine($row->engine),
        );
        if (! $preflightResult['ok']) {
            $message = (string) ($preflightResult['reason'] ?? 'Insufficient resources.');
            $row->update([
                'status' => ServerCacheService::STATUS_FAILED,
                'error_message' => Str::limit($message, 800),
            ]);
            $audit->record($row->server, ServerCacheServiceAuditEvent::EVENT_INSTALL_FAILED, [
                'engine' => $row->engine,
                'phase' => 'preflight',
                'error' => $message,
                'available_ram_mb' => $preflightResult['available_ram_mb'],
                'available_disk_mb' => $preflightResult['available_disk_mb'],
                'required_ram_mb' => $preflightResult['required_ram_mb'],
                'required_disk_mb' => $preflightResult['required_disk_mb'],
            ]);

            return;
        }

        try {
            $script = CacheServiceInstallScripts::installScript($row->engine).
                "\n".CacheServiceInstallScripts::versionProbeScript($row->engine);

            $output = $executor->runInlineBash(
                $row->server,
                'cache-service:install:'.$row->engine,
                $script,
                timeoutSeconds: 900,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Install command failed.'
                );
            }

            $version = $this->parseVersion($output->buffer);

            $row->update([
                'status' => ServerCacheService::STATUS_RUNNING,
                'version' => $version,
                'port' => ServerCacheService::defaultPortFor($row->engine),
            ]);

            // Bust the capability probe cache so the workspace renders the freshly-installed engine
            // without waiting for the 120s TTL.
            $capabilities->forget($row->server);

            // No actor on a queued job — record `user_id=null`. The Livewire action that queued
            // the job already toasted the operator; the audit row's job is to capture
            // when/what/where, not who clicked the button.
            $audit->record($row->server, ServerCacheServiceAuditEvent::EVENT_INSTALLED, [
                'engine' => $row->engine,
                'version' => $version,
                'port' => $row->port,
            ]);
        } catch (\Throwable $e) {
            $row->update([
                'status' => ServerCacheService::STATUS_FAILED,
                'error_message' => Str::limit($e->getMessage(), 800),
            ]);

            $audit->record($row->server, ServerCacheServiceAuditEvent::EVENT_INSTALL_FAILED, [
                'engine' => $row->engine,
                'error' => Str::limit($e->getMessage(), 800),
            ]);
        }
    }

    /**
     * The version probe script's last non-empty line is the version string. Some engines emit
     * extra lines (Dragonfly's `--version` includes a banner), so we walk from the end.
     */
    private function parseVersion(string $stdout): ?string
    {
        $lines = array_filter(array_map('trim', explode("\n", $stdout)), fn ($l) => $l !== '');
        $last = end($lines);

        return is_string($last) && $last !== '' ? Str::limit($last, 64, '') : null;
    }
}
