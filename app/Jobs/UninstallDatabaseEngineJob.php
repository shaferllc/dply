<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerDatabaseEngine;
use App\Models\ServerDatabaseEngineAuditEvent;
use App\Services\Servers\DatabaseEngineAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerDatabaseHostCapabilities;
use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class UninstallDatabaseEngineJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

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
    ): void {
        /** @var ServerDatabaseEngine|null $row */
        $row = ServerDatabaseEngine::query()->with('server')->find($this->serverDatabaseEngineId);
        if (! $row) {
            return;
        }

        $engine = $row->engine;
        $row->update([
            'status' => ServerDatabaseEngine::STATUS_UNINSTALLING,
            'error_message' => null,
        ]);

        try {
            $output = $executor->runInlineBash(
                $row->server,
                'database-engine:uninstall:'.$engine,
                DatabaseEngineInstallScripts::uninstallScript($engine),
                timeoutSeconds: 1200,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Uninstall command failed.'
                );
            }

            $capabilities->forget($row->server);

            // Capture engine name + server before delete so the audit row doesn't reference a
            // gone-by-the-time-we-record entity.
            $serverForAudit = $row->server;
            $row->delete();

            $audit->record($serverForAudit, ServerDatabaseEngineAuditEvent::EVENT_ENGINE_UNINSTALLED, [
                'engine' => $engine,
            ]);
        } catch (\Throwable $e) {
            $row->update([
                'status' => ServerDatabaseEngine::STATUS_FAILED,
                'error_message' => Str::limit($e->getMessage(), 800),
            ]);
            $audit->record($row->server, ServerDatabaseEngineAuditEvent::EVENT_ENGINE_UNINSTALL_FAILED, [
                'engine' => $engine,
                'error' => Str::limit($e->getMessage(), 800),
            ]);
        }
    }
}
