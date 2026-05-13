<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\RemoteWebserverConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Run a mutating webserver-config-editor operation (save / validate-buffer /
 * restore) on a worker so the Livewire ConsoleAction banner can render the
 * full queued → running → completed lifecycle. Running these inline in the
 * Livewire request made the banner flash directly to its terminal state,
 * since the SSH work blocked the response.
 *
 * Each op shares the same shape: emitter wired to the seeded ConsoleAction
 * row, service method called with the emitter, row marked completed/failed
 * at the end based on the return shape.
 */
class RunWebserverConfigOpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(
        public string $serverId,
        public string $consoleActionId,
        public string $op, // 'write' | 'validate' | 'restore'
        public string $engine,
        public string $path,
        public string $contents = '',
        public string $backupPath = '',
    ) {}

    public function handle(RemoteWebserverConfigService $service): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            $this->markConsole(ConsoleAction::STATUS_FAILED, error: 'Server not found.');

            return;
        }

        $this->markConsole(ConsoleAction::STATUS_RUNNING, started: true);
        $emitter = new ConsoleEmitter($this->consoleActionId);

        try {
            $result = match ($this->op) {
                'write' => $service->write($server, $this->engine, $this->path, $this->contents, $emitter),
                'validate' => $service->validateContent($server, $this->engine, $this->path, $this->contents, $emitter),
                'restore' => $service->restoreBackup($server, $this->engine, $this->backupPath, $this->path, $emitter),
                default => throw new \InvalidArgumentException('Unknown op: '.$this->op),
            };
        } catch (\Throwable $e) {
            $this->markConsole(ConsoleAction::STATUS_FAILED, error: $e->getMessage());

            return;
        }

        // 'validate' returns ['output', 'ok']; 'write' + 'restore' return
        // ['backup', 'validate_output', 'validate_ok', 'reverted'?]. Normalise
        // the success check + error message across both shapes.
        $ok = (bool) ($result['ok'] ?? $result['validate_ok'] ?? false);
        $errBlob = $ok ? null : (string) ($result['output'] ?? $result['validate_output'] ?? '');

        $this->markConsole(
            $ok ? ConsoleAction::STATUS_COMPLETED : ConsoleAction::STATUS_FAILED,
            error: $errBlob,
        );
    }

    public function failed(?\Throwable $e): void
    {
        $this->markConsole(ConsoleAction::STATUS_FAILED, error: $e?->getMessage() ?? 'Job failed.');
    }

    private function markConsole(string $status, bool $started = false, ?string $error = null): void
    {
        $payload = ['status' => $status, 'updated_at' => now()];
        if ($started) {
            $payload['started_at'] = DB::raw('coalesce(started_at, now())');
        }
        if (in_array($status, [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED], true)) {
            $payload['finished_at'] = now();
            $payload['error'] = $error === null ? null : mb_substr($error, 0, 2000);
        }
        DB::table('console_actions')->where('id', $this->consoleActionId)->update($payload);
    }
}
