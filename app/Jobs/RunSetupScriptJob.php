<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\SshConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunSetupScriptJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $server = $this->server->fresh();
        if (! $server || ! $server->setup_script_key || empty($server->ip_address) || $server->status !== Server::STATUS_READY) {
            return;
        }

        $scripts = config('setup_scripts.scripts', []);
        $script = $scripts[$server->setup_script_key] ?? null;
        if (! $script || empty($script['commands'])) {
            $server->update(['setup_status' => Server::SETUP_STATUS_FAILED]);

            return;
        }

        $server->update(['setup_status' => Server::SETUP_STATUS_RUNNING]);
        $timeout = config('setup_scripts.command_timeout', 300);

        try {
            $ssh = new SshConnection($server);
            if (! $ssh->connect($timeout + 5)) {
                $server->update(['setup_status' => Server::SETUP_STATUS_FAILED]);

                return;
            }

            foreach ($script['commands'] as $command) {
                $cmd = trim($command);
                if ($cmd === '') {
                    continue;
                }
                $ssh->exec("export DEBIAN_FRONTEND=noninteractive; {$cmd}");
            }

            $server->update(['setup_status' => Server::SETUP_STATUS_DONE]);
        } catch (\Throwable $e) {
            Log::warning('Setup script failed for server.', [
                'server_id' => $server->id,
                'setup_script_key' => $server->setup_script_key,
                'error' => $e->getMessage(),
            ]);
            $server->update(['setup_status' => Server::SETUP_STATUS_FAILED]);
        }
    }
}
