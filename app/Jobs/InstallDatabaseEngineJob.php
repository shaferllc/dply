<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerDatabaseEngineAuditEvent;
use App\Models\User;
use App\Services\Notifications\ServerDatabaseNotificationDispatcher;
use App\Services\Servers\DatabaseEngineAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerDatabaseRemoteExec;
use App\Support\Servers\DatabaseEngineInstallScripts;
use App\Support\Servers\ServerDatabaseHostCapabilities;
use App\Support\Servers\ServerResourcePreflight;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Install a database engine (mysql / mariadb / postgres) on a server post-provision. Mirrors the
 * cache install job: preflight resources, run the apt + systemctl bash, parse the version, mark
 * the row running. Failures land the row in `failed` with a clear error message so the operator
 * can retry from the workspace.
 *
 * Progress streams into a {@see ConsoleAction} on the engine row so the databases workspace
 * banner shows live apt output (same pattern as webserver switch).
 */
class InstallDatabaseEngineJob implements ShouldQueue
{
    use Queueable;
    use WritesConsoleAction;

    public int $timeout = 1800; // apt-get on a 1 GB box can run a few minutes for mysql

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
        return 'db_engine_install';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        ServerDatabaseHostCapabilities $capabilities,
        DatabaseEngineAuditLogger $audit,
        ServerResourcePreflight $preflight,
        ServerDatabaseNotificationDispatcher $notifications,
    ): void {
        /** @var ServerDatabaseEngine|null $row */
        $row = ServerDatabaseEngine::query()->with('server')->find($this->serverDatabaseEngineId);
        if (! $row) {
            return;
        }

        $emit = $this->beginConsoleAction();

        $preflightResult = $preflight->check(
            $row->server,
            ServerResourcePreflight::requirementsForDatabaseEngine($row->engine),
        );
        if (! $preflightResult['ok']) {
            $message = (string) ($preflightResult['reason'] ?? 'Insufficient resources.');
            $emit->error('db', $message);
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
            $this->failConsoleAction($message);

            return;
        }

        $row->update([
            'status' => ServerDatabaseEngine::STATUS_INSTALLING,
            'error_message' => null,
        ]);

        $emit->step('db', __('Running apt install for :engine …', ['engine' => $row->engine]));

        try {
            $script = DatabaseEngineInstallScripts::installScript($row->engine).
                "\n".DatabaseEngineInstallScripts::versionProbeScript($row->engine);

            $output = $executor->runInlineBashWithOutputCallback(
                $row->server,
                'database-engine:install:'.$row->engine,
                $script,
                function (string $type, string $chunk) use ($emit): void {
                    foreach (preg_split("/\r?\n/", $chunk) ?: [] as $line) {
                        $line = trim($line);
                        if ($line !== '') {
                            $emit($line, ConsoleAction::LEVEL_INFO, 'apt');
                        }
                    }
                },
                timeoutSeconds: 1800,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Install command failed.'
                );
            }

            $version = $this->parseVersion($output->buffer);

            // The app dials the engine over TCP 127.0.0.1:<port>, but apt only
            // guarantees the daemon is up — not that it's listening on loopback
            // TCP. Verify, remediate if not, and re-verify so an engine never lands
            // "running" while being unreachable to the very apps it's installed for.
            $this->ensureLoopbackListening($row, $executor, $emit);

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

            $notifications->notify(
                $row->server,
                'engine_installed',
                [__('Engine: :engine :version', ['engine' => $row->engine, 'version' => (string) $version])],
                $this->userId !== null ? User::query()->find($this->userId) : null,
                ['engine' => $row->engine, 'version' => $version, 'port' => $row->port],
            );

            $emit->success('db', __(':engine is running.', ['engine' => $row->engine]));
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $message = Str::limit($e->getMessage(), 800);
            $row->update([
                'status' => ServerDatabaseEngine::STATUS_FAILED,
                'error_message' => $message,
            ]);
            $audit->record($row->server, ServerDatabaseEngineAuditEvent::EVENT_ENGINE_INSTALL_FAILED, [
                'engine' => $row->engine,
                'error' => $message,
            ]);
            $emit->error('db', $message);
            $this->failConsoleAction($message);
        }
    }

    /**
     * Ensure the freshly-installed engine accepts TCP on 127.0.0.1:<port>. Only
     * enforced for the relational engines apps connect to over TCP loopback
     * (postgres/mysql/mariadb). No-op when already listening — remediation runs
     * only on failure, so a working config is never touched. Throws (→ FAILED)
     * when the engine still isn't reachable after remediation.
     */
    private function ensureLoopbackListening(ServerDatabaseEngine $row, ExecuteRemoteTaskOnServer $executor, $emit): void
    {
        $engine = $row->engine;
        if (! in_array($engine, ['postgres', 'mysql', 'mariadb'], true)) {
            return;
        }

        $remote = app(ServerDatabaseRemoteExec::class);
        if ($remote->engineListeningOnLoopback($row->server, $engine)) {
            return;
        }

        $emit->step('db', __('Engine not reachable on 127.0.0.1 yet — applying loopback listen config …'));
        $script = DatabaseEngineInstallScripts::ensureLoopbackListeningScript($engine);
        if ($script !== '') {
            $executor->runInlineBash(
                $row->server,
                'database-engine:ensure-listen:'.$engine,
                $script,
                timeoutSeconds: 120,
                asRoot: true,
            );
        }

        if (! $remote->engineListeningOnLoopback($row->server, $engine)) {
            throw new \RuntimeException(__(':engine installed but is not listening on 127.0.0.1::port even after remediation — check its service and config.', [
                'engine' => $engine,
                'port' => DatabaseEngineInstallScripts::defaultPortFor($engine),
            ]));
        }

        $emit->success('db', __(':engine is listening on 127.0.0.1::port.', [
            'engine' => $engine,
            'port' => DatabaseEngineInstallScripts::defaultPortFor($engine),
        ]));
    }

    private function parseVersion(string $stdout): ?string
    {
        $lines = array_filter(array_map('trim', explode("\n", $stdout)), fn ($l) => $l !== '');
        $last = end($lines);

        return is_string($last) && $last !== '' ? Str::limit($last, 64, '') : null;
    }
}
