<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Cloud APIs often return a public IP before sshd is accepting connections. This job waits
 * for TCP port 22 (or ssh_port) then dispatches {@see RunSetupScriptJob}.
 */
class WaitForServerSshReadyJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 45;

    public function __construct(
        public Server $server
    ) {
        $this->tries = max(5, (int) config('server_provision.ssh_ready_max_attempts', 45));
    }

    public function handle(): void
    {
        $server = $this->server->fresh();
        if ($server === null || $server->status !== Server::STATUS_READY || empty($server->ip_address)) {
            return;
        }

        $retrySeconds = max(3, (int) config('server_provision.ssh_ready_retry_seconds', 8));

        $host = trim((string) $server->ip_address);
        $port = (int) ($server->ssh_port ?: 22);

        if ($this->tcpPortOpen($host, $port)) {
            RunSetupScriptJob::dispatch($server);

            return;
        }

        if ($this->attempts() >= $this->tries) {
            Log::warning('SSH port did not become reachable before max attempts; stack setup was not started.', [
                'server_id' => $server->id,
                'ip' => $host,
                'port' => $port,
                'attempts' => $this->attempts(),
                'max_tries' => $this->tries,
            ]);

            return;
        }

        $this->release($retrySeconds);
    }

    private function tcpPortOpen(string $host, int $port, int $timeoutSeconds = 5): bool
    {
        $errno = 0;
        $errstr = '';
        $context = stream_context_create([
            'socket' => [
                'connect_timeout' => $timeoutSeconds,
            ],
        ]);

        $fp = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! is_resource($fp)) {
            return false;
        }

        fclose($fp);

        return true;
    }
}
