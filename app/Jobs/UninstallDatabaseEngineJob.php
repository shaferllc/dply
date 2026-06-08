<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerDatabaseEngineAuditEvent;
use App\Services\Servers\DatabaseEngineAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\DatabaseEngineInstallScripts;
use App\Support\Servers\ServerDatabaseHostCapabilities;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class UninstallDatabaseEngineJob implements ShouldQueue
{
    use Queueable;
    use WritesConsoleAction;

    public int $timeout = 1200;

    public function __construct(
        public string $serverDatabaseEngineId,
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
        return 'db_engine_uninstall';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        ServerDatabaseHostCapabilities $capabilities,
        DatabaseEngineAuditLogger $audit,
        \App\Services\Notifications\ServerDatabaseNotificationDispatcher $notifications,
    ): void {
        /** @var ServerDatabaseEngine|null $row */
        $row = ServerDatabaseEngine::query()->with('server')->find($this->serverDatabaseEngineId);
        if (! $row) {
            return;
        }

        $engine = $row->engine;
        $emit = $this->beginConsoleAction();

        $row->update([
            'status' => ServerDatabaseEngine::STATUS_UNINSTALLING,
            'error_message' => null,
        ]);

        $emit->step('db', __('Removing :engine via apt …', ['engine' => $engine]));

        try {
            $output = $executor->runInlineBashWithOutputCallback(
                $row->server,
                'database-engine:uninstall:'.$engine,
                DatabaseEngineInstallScripts::uninstallScript($engine),
                function (string $type, string $chunk) use ($emit): void {
                    foreach (preg_split("/\r?\n/", $chunk) ?: [] as $line) {
                        $line = trim($line);
                        if ($line !== '') {
                            $emit($line, ConsoleAction::LEVEL_INFO, 'apt');
                        }
                    }
                },
                timeoutSeconds: 1200,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Uninstall command failed.'
                );
            }

            $capabilities->forget($row->server);

            $serverForAudit = $row->server;
            $row->delete();

            $audit->record($serverForAudit, ServerDatabaseEngineAuditEvent::EVENT_ENGINE_UNINSTALLED, [
                'engine' => $engine,
            ]);

            $notifications->notify(
                $serverForAudit,
                'engine_removed',
                [__('Engine: :engine', ['engine' => $engine])],
                $this->userId !== null ? \App\Models\User::query()->find($this->userId) : null,
                ['engine' => $engine],
            );

            $emit->success('db', __(':engine removed.', ['engine' => $engine]));
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $message = Str::limit($e->getMessage(), 800);
            $row->update([
                'status' => ServerDatabaseEngine::STATUS_FAILED,
                'error_message' => $message,
            ]);
            $audit->record($row->server, ServerDatabaseEngineAuditEvent::EVENT_ENGINE_UNINSTALL_FAILED, [
                'engine' => $engine,
                'error' => $message,
            ]);
            $emit->error('db', $message);
            $this->failConsoleAction($message);
        }
    }
}
