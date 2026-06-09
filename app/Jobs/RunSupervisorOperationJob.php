<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class RunSupervisorOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public readonly string $serverId,
        public readonly string $operation,  // 'sync' | 'install' | 'restart_all'
        public readonly string $runId,
    ) {}

    public static function cacheKey(string $runId): string
    {
        return 'sv_op:'.$runId;
    }

    public function handle(SupervisorProvisioner $provisioner): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            $this->store('failed', 'Server not found.');
            return;
        }

        $this->store('running', '');

        try {
            $out = match ($this->operation) {
                'sync'        => $provisioner->sync($server->fresh()),
                'install'     => $provisioner->installSupervisorPackage($server->fresh()),
                'restart_all' => $provisioner->restartAllManagedPrograms($server->fresh()),
                default       => throw new \InvalidArgumentException("Unknown operation: {$this->operation}"),
            };

            // Mark package as installed after successful install/sync.
            if (in_array($this->operation, ['sync', 'install'], true)) {
                $server->refresh()->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);
            }

            $this->store('done', trim($out));
        } catch (\Throwable $e) {
            $this->store('failed', $e->getMessage());
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->store('failed', $exception?->getMessage() ?? 'The operation failed unexpectedly.');
    }

    private function store(string $status, string $output): void
    {
        Cache::put(self::cacheKey($this->runId), compact('status', 'output'), now()->addMinutes(10));
    }
}
