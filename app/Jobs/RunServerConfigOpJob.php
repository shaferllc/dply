<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\User;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\RemoteServerConfigService;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerConfigFileCatalog;
use App\Services\Servers\ServerConfigFileEditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Run a config-editor operation (read / write / validate / restore) on a worker
 * for the unified Configuration workspace. Webserver engine paths delegate to
 * {@see RemoteWebserverConfigService}; all other allowlisted paths use
 * {@see RemoteServerConfigService}.
 */
class RunServerConfigOpJob implements ShouldQueue
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
        public string $op,
        public string $path,
        public string $contents = '',
        public string $backupPath = '',
        public ?string $userId = null,
        public bool $recordRevision = false,
        public ?string $revisionSummary = null,
        public ?string $engine = null,
    ) {}

    public function handle(
        RemoteWebserverConfigService $webserverService,
        RemoteServerConfigService $genericService,
    ): void {
        $server = Server::find($this->serverId);
        if ($server === null) {
            $this->markConsole(ConsoleAction::STATUS_FAILED, error: 'Server not found.');

            return;
        }

        $this->markConsole(ConsoleAction::STATUS_RUNNING, started: true);
        $emitter = new ConsoleEmitter($this->consoleActionId);

        try {
            $engine = $this->resolvedWebserverEngine();
            $result = $engine !== null
                ? match ($this->op) {
                    'write' => $webserverService->write($server, $engine, $this->path, $this->contents, $emitter),
                    'validate' => $webserverService->validateContent($server, $engine, $this->path, $this->contents, $emitter),
                    'restore' => $webserverService->restoreBackup($server, $engine, $this->backupPath, $this->path, $emitter),
                    'read' => $webserverService->read($server, $engine, $this->path, $emitter),
                    default => throw new \InvalidArgumentException('Unknown op: '.$this->op),
                }
            : match ($this->op) {
                'write' => $genericService->write($server, $this->path, $this->contents, $emitter),
                'validate' => $genericService->validateContent($server, $this->path, $this->contents, $emitter),
                'restore' => $genericService->restoreBackup($server, $this->backupPath, $this->path, $emitter),
                'read' => $genericService->read($server, $this->path, $emitter),
                default => throw new \InvalidArgumentException('Unknown op: '.$this->op),
            };
        } catch (\Throwable $e) {
            $this->markConsole(ConsoleAction::STATUS_FAILED, error: $e->getMessage());

            return;
        }

        if ($this->op === 'read') {
            Cache::put(
                self::readResultCacheKey($this->consoleActionId),
                $result,
                now()->addMinutes(5),
            );
            self::storeFileContentCache($this->serverId, $this->path, $result);
            $this->markConsole(ConsoleAction::STATUS_COMPLETED, error: null);

            return;
        }

        $ok = (bool) ($result['ok'] ?? $result['validate_ok'] ?? false);
        $errBlob = $ok ? null : (string) ($result['output'] ?? $result['validate_output'] ?? '');

        if ($ok && $this->op === 'write' && $this->recordRevision) {
            app(ServerConfigFileEditor::class)->recordWrite(
                $server,
                $this->path,
                $this->contents,
                $this->userId !== null ? User::find($this->userId) : null,
                $this->revisionSummary,
                $this->engine,
            );

            Cache::put(
                self::writeResultCacheKey($this->consoleActionId),
                ['contents' => $this->contents],
                now()->addMinutes(5),
            );

            self::storeFileContentCache($this->serverId, $this->path, [
                'contents' => $this->contents,
                'truncated' => false,
            ]);
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
        return 'dply.server-config-read:'.$consoleActionId;
    }

    public static function writeResultCacheKey(string $consoleActionId): string
    {
        return 'dply.server-config-write:'.$consoleActionId;
    }

    public static function validateResultCacheKey(string $consoleActionId): string
    {
        return 'dply.server-config-validate:'.$consoleActionId;
    }

    public static function fileContentCacheKey(string $serverId, string $path): string
    {
        return 'dply.server-config-file-content:'.$serverId.':'.hash('xxh128', $path);
    }

    /**
     * @param  array{contents?: string, truncated?: bool, size?: int|null}  $result
     */
    public static function storeFileContentCache(string $serverId, string $path, array $result): void
    {
        Cache::put(
            self::fileContentCacheKey($serverId, $path),
            [
                'contents' => (string) ($result['contents'] ?? ''),
                'truncated' => (bool) ($result['truncated'] ?? false),
                'cached_at' => now()->toIso8601String(),
                'size' => $result['size'] ?? null,
            ],
            now()->addMinutes((int) config('server_manage.config_file_content_cache_minutes', 30)),
        );
    }

    public static function forgetFileContentCache(string $serverId, string $path): void
    {
        Cache::forget(self::fileContentCacheKey($serverId, $path));
    }

    public function failed(?\Throwable $e): void
    {
        $this->markConsole(ConsoleAction::STATUS_FAILED, error: $e?->getMessage() ?? 'Job failed.');
    }

    private function resolvedWebserverEngine(): ?string
    {
        if (is_string($this->engine) && $this->engine !== '') {
            return $this->engine;
        }

        return app(ServerConfigFileCatalog::class)->webserverEngineForPath($this->path);
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
