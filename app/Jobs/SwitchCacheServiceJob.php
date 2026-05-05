<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceAuth;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\CacheServiceMemoryConfig;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use App\Support\Servers\ServerResourcePreflight;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Atomic-ish swap from one cache engine to another. The single row in `server_cache_services`
 * is mutated in place — we don't tear down and recreate so audit history stays attached to one
 * id. Phase 1's invariant of "one engine per server" still holds because the row's `engine`
 * column changes; the unique-on-server-id index protects against runaway concurrent switches.
 *
 * Settings preservation across the swap:
 *   - AUTH password: copied if BOTH old and new engines support it (all redis-family pairs).
 *     Memcached has no requirepass so any switch involving memcached drops the password.
 *   - maxmemory + maxmemory-policy: read from the OLD engine before uninstall, applied after the
 *     new engine is installed and verified, but only when the new engine accepts those directives
 *     (i.e. only redis-family → redis-family). Memcached has different memory mechanics.
 *
 * Failure modes:
 *   - Uninstall fails → row stays on the old engine in `failed` status with the error message.
 *     Operator can retry the switch or fall back to manual uninstall.
 *   - Install fails after a successful uninstall → row reflects the NEW engine in `failed`. The
 *     server is left without a running cache; operator can retry install from the engine tab.
 *   - Settings re-apply fails → engine is up but settings weren't carried over; row marked
 *     `running` with a warning in error_message so operator knows to revisit.
 */
class SwitchCacheServiceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1500; // uninstall + install + reapply on a small box can run ~10–20 min

    public function __construct(
        public string $serverCacheServiceId,
        public string $targetEngine,
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
        CacheServiceAuth $auth,
        CacheServiceMemoryConfig $memory,
        ServerResourcePreflight $preflight,
    ): void {
        $row = ServerCacheService::query()->with('server')->find($this->serverCacheServiceId);
        if (! $row) {
            return;
        }

        if ($row->engine === $this->targetEngine) {
            // Defensive: the Livewire action rejects same-engine switches before we get here.
            return;
        }

        $oldEngine = $row->engine;
        $newEngine = $this->targetEngine;

        // Preflight against the NEW engine's resource needs BEFORE we tear down the old one. If
        // the box can't fit the new engine, we leave the old one in place and surface the reason.
        $preflightResult = $preflight->check(
            $row->server,
            ServerResourcePreflight::requirementsForCacheEngine($newEngine),
        );
        if (! $preflightResult['ok']) {
            $message = (string) ($preflightResult['reason'] ?? 'Insufficient resources.');
            $row->update([
                'status' => ServerCacheService::STATUS_FAILED,
                'error_message' => Str::limit('Switch blocked by resource preflight: '.$message, 800),
            ]);
            $audit->record($row->server, ServerCacheServiceAuditEvent::EVENT_SWITCH_FAILED, [
                'from' => $oldEngine,
                'to' => $newEngine,
                'phase' => 'preflight',
                'error' => $message,
                'available_ram_mb' => $preflightResult['available_ram_mb'],
                'available_disk_mb' => $preflightResult['available_disk_mb'],
                'required_ram_mb' => $preflightResult['required_ram_mb'],
                'required_disk_mb' => $preflightResult['required_disk_mb'],
            ]);

            return;
        }
        $preservedAuth = $row->auth_password;
        $preservedMemory = ['maxmemory' => null, 'maxmemory_policy' => null];

        // Best-effort capture of memory settings BEFORE uninstall — only meaningful if both old
        // and new engines support the maxmemory directive.
        if (ServerCacheService::engineSupportsAuth($oldEngine) && ServerCacheService::engineSupportsAuth($newEngine)) {
            try {
                $preservedMemory = $memory->read($row->server, $row);
            } catch (\Throwable) {
                // Memory snapshot is opportunistic; failing here just means we don't carry the
                // settings forward. The switch itself proceeds.
            }
        }

        // Mark the row as installing the NEW engine immediately so the workspace UI reflects what's
        // about to happen. The unique server_id index plus this in-place edit avoids a window
        // where the server appears to have no cache row at all.
        $row->update([
            'status' => ServerCacheService::STATUS_INSTALLING,
            'engine' => $newEngine,
            'port' => ServerCacheService::defaultPortFor($newEngine),
            'version' => null,
            'error_message' => null,
            'auth_password' => null, // wiped now; re-applied after install if compatible
        ]);

        // Step 1: uninstall the OLD engine.
        try {
            $output = $executor->runInlineBash(
                $row->server,
                'cache-service:switch-uninstall:'.$oldEngine,
                CacheServiceInstallScripts::uninstallScript($oldEngine),
                timeoutSeconds: 600,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(Str::limit(trim($output->buffer), 800)
                    ?: 'Uninstall command failed.');
            }
        } catch (\Throwable $e) {
            // Restore the row to the old engine + a failed status so the operator can retry.
            $row->update([
                'engine' => $oldEngine,
                'port' => ServerCacheService::defaultPortFor($oldEngine),
                'status' => ServerCacheService::STATUS_FAILED,
                'error_message' => Str::limit('Switch failed during uninstall of '.$oldEngine.': '.$e->getMessage(), 800),
                'auth_password' => $preservedAuth,
            ]);
            $audit->record($row->server, ServerCacheServiceAuditEvent::EVENT_SWITCH_FAILED, [
                'from' => $oldEngine,
                'to' => $newEngine,
                'phase' => 'uninstall',
                'error' => Str::limit($e->getMessage(), 800),
            ]);

            return;
        }

        // Step 2: install the NEW engine.
        try {
            $script = CacheServiceInstallScripts::installScript($newEngine).
                "\n".CacheServiceInstallScripts::versionProbeScript($newEngine);

            $output = $executor->runInlineBash(
                $row->server,
                'cache-service:switch-install:'.$newEngine,
                $script,
                timeoutSeconds: 900,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(Str::limit(trim($output->buffer), 800)
                    ?: 'Install command failed.');
            }

            $version = $this->parseVersion($output->buffer);
            $row->update([
                'status' => ServerCacheService::STATUS_RUNNING,
                'version' => $version,
            ]);
        } catch (\Throwable $e) {
            $row->update([
                'status' => ServerCacheService::STATUS_FAILED,
                'error_message' => Str::limit('Switch failed during install of '.$newEngine.': '.$e->getMessage(), 800),
            ]);
            $audit->record($row->server, ServerCacheServiceAuditEvent::EVENT_SWITCH_FAILED, [
                'from' => $oldEngine,
                'to' => $newEngine,
                'phase' => 'install',
                'error' => Str::limit($e->getMessage(), 800),
            ]);

            return;
        }

        // Step 3: re-apply auth password if both engines support it. Failures are non-fatal —
        // the cache is up; the operator just needs to set the password manually from the workspace.
        $reapplyWarnings = [];
        if (filled($preservedAuth) && ServerCacheService::engineSupportsAuth($newEngine)) {
            try {
                $auth->setRequirePass($row->server, $row->fresh(), $preservedAuth);
                $row->update(['auth_password' => $preservedAuth]);
            } catch (\Throwable $e) {
                $reapplyWarnings[] = 'auth password not re-applied: '.$e->getMessage();
            }
        }

        // Step 4: re-apply memory settings if relevant.
        $hasMemoryToReapply = ($preservedMemory['maxmemory'] !== null || $preservedMemory['maxmemory_policy'] !== null);
        if ($hasMemoryToReapply && ServerCacheService::engineSupportsAuth($newEngine)) {
            try {
                $memory->write(
                    $row->server,
                    $row->fresh(),
                    $preservedMemory['maxmemory'],
                    $preservedMemory['maxmemory_policy'],
                );
            } catch (\Throwable $e) {
                $reapplyWarnings[] = 'memory settings not re-applied: '.$e->getMessage();
            }
        }

        // Surface any non-fatal warnings on the row + audit so the operator can see them.
        if ($reapplyWarnings !== []) {
            $row->update([
                'error_message' => Str::limit(implode(' | ', $reapplyWarnings), 800),
            ]);
        }

        $capabilities->forget($row->server);

        $audit->record($row->server, ServerCacheServiceAuditEvent::EVENT_SWITCHED, [
            'from' => $oldEngine,
            'to' => $newEngine,
            'auth_carried' => filled($preservedAuth) && ServerCacheService::engineSupportsAuth($newEngine),
            'memory_carried' => $hasMemoryToReapply && ServerCacheService::engineSupportsAuth($newEngine),
            'warnings' => $reapplyWarnings ?: null,
        ]);
    }

    private function parseVersion(string $stdout): ?string
    {
        $lines = array_filter(array_map('trim', explode("\n", $stdout)), fn ($l) => $l !== '');
        $last = end($lines);

        return is_string($last) && $last !== '' ? Str::limit($last, 64, '') : null;
    }
}
