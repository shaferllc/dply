<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerDatabaseEngine;
use App\Models\ServerDatabaseEngineAuditEvent;
use App\Services\Servers\DatabaseEngineAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerDatabaseHostCapabilities;
use App\Support\Servers\DatabaseEngineInstallScripts;
use App\Support\Servers\ServerResourcePreflight;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Install a database engine (mysql / mariadb / postgres) on a server post-provision. Mirrors the
 * cache install job: preflight resources, run the apt + systemctl bash, parse the version, mark
 * the row running. Failures land the row in `failed` with a clear error message so the operator
 * can retry from the workspace.
 */
class InstallDatabaseEngineJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800; // apt-get on a 1 GB box can run a few minutes for mysql

    public function __construct(
        public string $serverDatabaseEngineId
    ) {
        $q = config('server_database.install_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        ServerDatabaseHostCapabilities $capabilities,
        DatabaseEngineAuditLogger $audit,
        ServerResourcePreflight $preflight,
    ): void {
        /** @var ServerDatabaseEngine|null $row */
        $row = ServerDatabaseEngine::query()->with('server')->find($this->serverDatabaseEngineId);
        if (! $row) {
            return;
        }

        $row->update([
            'status' => ServerDatabaseEngine::STATUS_INSTALLING,
            'error_message' => null,
        ]);

        $preflightResult = $preflight->check(
            $row->server,
            ServerResourcePreflight::requirementsForDatabaseEngine($row->engine),
        );
        if (! $preflightResult['ok']) {
            $message = (string) ($preflightResult['reason'] ?? 'Insufficient resources.');
            $row->update([
                'status' => ServerDatabaseEngine::STATUS_FAILED,
                'error_message' => Str::limit($message, 800),
            ]);
            $audit->record($row->server, ServerDatabaseEngineAuditEvent::EVENT_ENGINE_INSTALL_FAILED, [
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
            $script = DatabaseEngineInstallScripts::installScript($row->engine).
                "\n".DatabaseEngineInstallScripts::versionProbeScript($row->engine);

            $output = $executor->runInlineBash(
                $row->server,
                'database-engine:install:'.$row->engine,
                $script,
                timeoutSeconds: 1800,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Install command failed.'
                );
            }

            $version = $this->parseVersion($output->buffer);

            $row->update([
                'status' => ServerDatabaseEngine::STATUS_RUNNING,
                'version' => $version,
                'port' => DatabaseEngineInstallScripts::defaultPortFor($row->engine),
            ]);

            $capabilities->forget($row->server);

            $audit->record($row->server, ServerDatabaseEngineAuditEvent::EVENT_ENGINE_INSTALLED, [
                'engine' => $row->engine,
                'version' => $version,
                'port' => $row->port,
            ]);
        } catch (\Throwable $e) {
            $row->update([
                'status' => ServerDatabaseEngine::STATUS_FAILED,
                'error_message' => Str::limit($e->getMessage(), 800),
            ]);
            $audit->record($row->server, ServerDatabaseEngineAuditEvent::EVENT_ENGINE_INSTALL_FAILED, [
                'engine' => $row->engine,
                'error' => Str::limit($e->getMessage(), 800),
            ]);
        }
    }

    private function parseVersion(string $stdout): ?string
    {
        $lines = array_filter(array_map('trim', explode("\n", $stdout)), fn ($l) => $l !== '');
        $last = end($lines);

        return is_string($last) && $last !== '' ? Str::limit($last, 64, '') : null;
    }
}
