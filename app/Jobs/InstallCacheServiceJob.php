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

        // Operator clicked Cancel between dispatch and worker pickup. Skip apt entirely and
        // delete the row — there's nothing on the server to revert because we never started.
        if ($row->cancel_requested_at !== null) {
            $this->finishCancellation($row, $executor, $capabilities, $audit, ranAptInstall: false);

            return;
        }

        $row->update([
            'status' => ServerCacheService::STATUS_INSTALLING,
            'error_message' => null,
            'install_output' => '',
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

        // Cancel may have been requested while preflight was running. Same path as above —
        // nothing on the server to clean up, just delete the row and audit.
        if ($row->fresh()?->cancel_requested_at !== null) {
            $this->finishCancellation($row, $executor, $capabilities, $audit, ranAptInstall: false);

            return;
        }

        try {
            // Use the row-aware composer so non-default-named instances get the
            // template unit + per-instance config scaffolding before the
            // systemctl enable. For the legacy `default` instance this is
            // identical to the old installScript path.
            $script = CacheServiceInstallScripts::installScriptForRow($row).
                "\n".CacheServiceInstallScripts::versionProbeScript($row->engine);

            // Stream stdout/stderr chunks back to the row so the workspace's 4s poll can show
            // a live tail of the install. We throttle DB writes to ~3s to keep the write rate
            // sane even when apt-get is chatty.
            $bufferAcc = '';
            $lastFlush = 0.0;
            $lastCancelCheck = 0.0;
            $rowId = $row->id;
            $flush = function (bool $force = false) use ($row, &$bufferAcc, &$lastFlush, &$lastCancelCheck, $rowId): void {
                $now = microtime(true);
                if (! $force && ($now - $lastFlush) < 3.0) {
                    return;
                }
                $lastFlush = $now;
                $row->update(['install_output' => mb_substr($bufferAcc, -32_000)]);

                // Polling cancel intent on each flush keeps the cost cheap (one SELECT every ~3s).
                // When the operator hits Cancel, the next chunk will see the flag and bail.
                if (($now - $lastCancelCheck) >= 1.5) {
                    $lastCancelCheck = $now;
                    $cancelAt = ServerCacheService::query()->whereKey($rowId)->value('cancel_requested_at');
                    if ($cancelAt !== null) {
                        throw new CacheInstallCancelledException;
                    }
                }
            };

            $output = $executor->runInlineBashWithOutputCallback(
                $row->server,
                'cache-service:install:'.$row->engine,
                $script,
                function (string $type, string $chunk) use (&$bufferAcc, $flush): void {
                    $bufferAcc .= $chunk;
                    $flush();
                },
                timeoutSeconds: 900,
                asRoot: true,
            );
            $flush(true);

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Install command failed.'
                );
            }

            // Apt may have completed successfully right before the operator clicked Cancel and
            // before our next poll fired. Honor the intent: revert what we just installed.
            if ($row->fresh()?->cancel_requested_at !== null) {
                $this->finishCancellation($row, $executor, $capabilities, $audit, ranAptInstall: true);

                return;
            }

            $version = CacheServiceInstallScripts::parseVersionFromBuffer($output->buffer);

            // Don't overwrite `port` — the row was created with the correct value at dispatch
            // time (default for the legacy single-instance install, autopicked or operator-chosen
            // for named instances). Forcing it back to defaultPortFor() collides with the
            // (server_id, port) unique constraint when another instance already sits on that
            // port and silently leaves the row stuck in INSTALLING.
            $row->update([
                'status' => ServerCacheService::STATUS_RUNNING,
                'version' => $version,
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
        } catch (CacheInstallCancelledException) {
            // Operator-initiated abort. The SSH stream was cut mid-script, so apt may be in a
            // half-configured state — finishCancellation runs `dpkg --configure -a` + purge.
            $this->finishCancellation($row, $executor, $capabilities, $audit, ranAptInstall: true);
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
     * Run best-effort revert and delete the row. `ranAptInstall=false` means we never made it past
     * preflight (or never started at all) and there's nothing to undo on the server, so we skip
     * the SSH round-trip.
     *
     * When OTHER instances of this engine exist on the same server (e.g. a default instance is
     * already running and the operator cancels mid-install of a named one), we never purge the
     * apt package — that would break the sibling instance. Instead we only roll back this
     * instance's specific scaffolding (templated systemd unit + per-instance config). The
     * package was already on the box before this run anyway, so leaving it costs nothing.
     */
    private function finishCancellation(
        ServerCacheService $row,
        ExecuteRemoteTaskOnServer $executor,
        ServerCacheServiceHostCapabilities $capabilities,
        CacheServiceAuditLogger $audit,
        bool $ranAptInstall,
    ): void {
        $engine = $row->engine;
        $name = $row->name;
        $serverForAudit = $row->server;
        $cleanupError = null;

        $otherInstances = ServerCacheService::query()
            ->where('server_id', $serverForAudit->id)
            ->where('engine', $engine)
            ->where('id', '!=', $row->id)
            ->count();
        $isLastInstance = $otherInstances === 0;

        if ($ranAptInstall) {
            try {
                $script = "export DEBIAN_FRONTEND=noninteractive\n".
                    "dpkg --configure -a 2>&1 || true\n".
                    "apt-get install -y --fix-broken 2>&1 || true\n".
                    CacheServiceInstallScripts::uninstallInstanceScript($engine, $name, $isLastInstance);

                $executor->runInlineBash(
                    $row->server,
                    'cache-service:install-cancel:'.$engine,
                    $script,
                    timeoutSeconds: 600,
                    asRoot: true,
                );
            } catch (\Throwable $e) {
                $cleanupError = Str::limit($e->getMessage(), 800);
            }
        }

        $capabilities->forget($row->server);
        $row->delete();

        $audit->record($serverForAudit, ServerCacheServiceAuditEvent::EVENT_INSTALL_CANCELLED, array_filter([
            'engine' => $engine,
            'name' => $name,
            'reverted' => $ranAptInstall,
            'package_purged' => $ranAptInstall && $isLastInstance,
            'cleanup_error' => $cleanupError,
        ], fn ($v) => $v !== null));
    }
}

/**
 * Internal sentinel — thrown from the install job's output callback when the operator's
 * `cancel_requested_at` flag is observed. Caught by the same job to switch into revert mode.
 */
final class CacheInstallCancelledException extends \RuntimeException {}
