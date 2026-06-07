<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\User;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerWebserverConfigEditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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
        public ?string $userId = null,
        public bool $recordRevision = false,
        public ?string $revisionSummary = null,
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
                'read' => $service->read($server, $this->engine, $this->path, $emitter),
                default => throw new \InvalidArgumentException('Unknown op: '.$this->op),
            };
        } catch (\Throwable $e) {
            $this->markConsole(ConsoleAction::STATUS_FAILED, error: $e->getMessage());

            return;
        }

        // 'read' returns ['contents', 'truncated', 'size'] — there's no
        // "ok" boolean since a cat either ran (success) or threw. Stash
        // the payload in cache keyed off the ConsoleAction id so the
        // Livewire component can pick it up on its next poll-driven
        // render and drop it into the editor buffer.
        if ($this->op === 'read') {
            // Fold the backups listing into this same worker job so the Livewire
            // pickup is a pure cache read. Previously refreshConfigBackups() ran
            // a second, inline SSH call inside the render() pickup — a whole
            // extra connection of latency on every load, and on the HTTP path
            // (against the queue-all-SSH rule). Non-fatal: backups are an aux
            // panel, so a listing failure must not sink the read itself.
            try {
                $result['backups'] = $service->listBackups($server, $this->engine, $this->path);
            } catch (\Throwable) {
                $result['backups'] = [];
            }
            Cache::put(
                self::readResultCacheKey($this->consoleActionId),
                $result,
                now()->addMinutes(5),
            );
            // Per-path content cache: lets a later click on the same file skip
            // the queue + SSH round-trip entirely and hydrate the editor inline
            // (see WorkspaceWebserver::loadWebserverConfig). Stamped so the UI
            // can show "from cache" and offer a re-read. Invalidated on the next
            // write/restore below so an edit never serves stale bytes.
            Cache::put(
                self::fileContentCacheKey($this->serverId, $this->engine, $this->path),
                $result + ['cached_at' => now()->toIso8601String()],
                now()->addMinutes(10),
            );
            $this->markConsole(ConsoleAction::STATUS_COMPLETED, error: null);

            return;
        }

        // 'validate' returns ['output', 'ok']; 'write' + 'restore' return
        // ['backup', 'validate_output', 'validate_ok', 'reverted'?]. Normalise
        // the success check + error message across both shapes.
        $ok = (bool) ($result['ok'] ?? $result['validate_ok'] ?? false);
        $errBlob = $ok ? null : (string) ($result['output'] ?? $result['validate_output'] ?? '');

        // A successful write/restore changes the live file, so drop the per-path
        // content cache — the next load re-reads fresh bytes (and re-caches).
        if ($ok && in_array($this->op, ['write', 'restore'], true)) {
            Cache::forget(self::fileContentCacheKey($this->serverId, $this->engine, $this->path));
        }

        if ($ok && $this->op === 'write' && $this->recordRevision) {
            app(ServerWebserverConfigEditor::class)->recordWrite(
                $server,
                $this->engine,
                $this->path,
                $this->contents,
                $this->userId !== null ? User::query()->find($this->userId) : null,
                $this->revisionSummary,
            );

            Cache::put(
                self::writeResultCacheKey($this->consoleActionId),
                ['contents' => $this->contents],
                now()->addMinutes(5),
            );
        }

        if ($ok && $this->op === 'validate') {
            Cache::put(
                self::validateResultCacheKey($this->consoleActionId),
                $result,
                now()->addMinutes(5),
            );
        }

        $this->markConsole(
            $ok ? ConsoleAction::STATUS_COMPLETED : ConsoleAction::STATUS_FAILED,
            error: $errBlob,
        );
    }

    public static function readResultCacheKey(string $consoleActionId): string
    {
        return 'dply.webserver-config-read:'.$consoleActionId;
    }

    /**
     * Per-(server, engine, path) cache of a file's contents + backups, so a
     * repeat load hydrates the editor inline without a queue + SSH round-trip.
     * Keyed by a hash of the path so it stays a safe cache key regardless of
     * slashes/length.
     */
    public static function fileContentCacheKey(string $serverId, string $engine, string $path): string
    {
        return 'dply.webserver-config-content:'.$serverId.':'.$engine.':'.sha1($path);
    }

    public static function writeResultCacheKey(string $consoleActionId): string
    {
        return 'dply.webserver-config-write:'.$consoleActionId;
    }

    public static function validateResultCacheKey(string $consoleActionId): string
    {
        return 'dply.webserver-config-validate:'.$consoleActionId;
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
