<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerDatabaseEngineAuditEvent;
use App\Services\Servers\DatabaseEngineAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Activate or deactivate an installed database engine over SSH.
 *
 * Deactivate = `systemctl disable --now <unit>` — stops the daemon and removes
 * it from startup, leaving binaries and data on disk. Activate = `enable --now`
 * — starts it and restores it at boot. The engine row flips to RUNNING/STOPPED
 * and progress streams into the databases workspace banner (same pattern as
 * install / remote-access toggle).
 */
class ToggleDatabaseEngineActivationJob implements ShouldQueue
{
    use Queueable;
    use WritesConsoleAction;

    public int $timeout = 300;

    public int $tries = 2;

    public int $backoff = 10;

    public function __construct(
        public string $serverDatabaseEngineId,
        public bool $activate,
        public ?string $userId = null,
    ) {
        $q = config('server_database.install_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
    }

    protected function consoleSubject(): Model
    {
        return ServerDatabaseEngine::query()->findOrFail($this->serverDatabaseEngineId);
    }

    protected function consoleKind(): string
    {
        return 'db_engine_activation';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        DatabaseEngineAuditLogger $audit,
    ): void {
        /** @var ServerDatabaseEngine|null $row */
        $row = ServerDatabaseEngine::query()->with('server')->find($this->serverDatabaseEngineId);
        if (! $row || ! $row->server) {
            return;
        }

        $emit = $this->beginConsoleAction();
        $verb = $this->activate ? __('Activating') : __('Deactivating');
        $emit->step('db', sprintf('%s %s …', $verb, $row->engine));

        try {
            $script = $this->activate
                ? DatabaseEngineInstallScripts::activateScript($row->engine)
                : DatabaseEngineInstallScripts::deactivateScript($row->engine);

            $output = $executor->runInlineBash(
                $row->server,
                'database-engine:activation:'.$row->engine,
                $script,
                timeoutSeconds: 120,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Activation command failed.'
                );
            }

            $row->update([
                'status' => $this->activate
                    ? ServerDatabaseEngine::STATUS_RUNNING
                    : ServerDatabaseEngine::STATUS_STOPPED,
                'error_message' => null,
            ]);

            $audit->record(
                $row->server,
                $this->activate
                    ? ServerDatabaseEngineAuditEvent::EVENT_ENGINE_ACTIVATED
                    : ServerDatabaseEngineAuditEvent::EVENT_ENGINE_DEACTIVATED,
                ['engine' => $row->engine],
            );

            $emit->success('db', $this->activate
                ? __(':engine is running and enabled at boot.', ['engine' => $row->engine])
                : __(':engine is stopped and disabled at boot.', ['engine' => $row->engine]));
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $message = Str::limit($e->getMessage(), 800);
            // Revert the optimistic flip the Livewire action applied: a failed
            // activate means it's still stopped; a failed deactivate means it's
            // (likely) still running.
            $row->update([
                'status' => $this->activate
                    ? ServerDatabaseEngine::STATUS_STOPPED
                    : ServerDatabaseEngine::STATUS_RUNNING,
                'error_message' => $message,
            ]);
            $audit->record($row->server, ServerDatabaseEngineAuditEvent::EVENT_ENGINE_ACTIVATION_FAILED, [
                'engine' => $row->engine,
                'activate' => $this->activate,
                'error' => $message,
            ]);
            $emit->error('db', $message);
            $this->failConsoleAction($message);
        }
    }
}
