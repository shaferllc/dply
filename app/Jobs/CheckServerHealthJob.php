<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class CheckServerHealthJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 15;

    private const CONNECT_TIMEOUT_SECONDS = 4;

    private const HTTP_TIMEOUT_SECONDS = 5;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $server = $this->server->fresh();
        if (! $server || $server->status !== Server::STATUS_READY || empty($server->ip_address)) {
            return;
        }

        $reachable = $this->checkHealth($server);

        $server->update([
            'last_health_check_at' => now(),
            'health_status' => $reachable ? Server::HEALTH_REACHABLE : Server::HEALTH_UNREACHABLE,
        ]);
    }

    private function checkHealth(Server $server): bool
    {
        $url = $server->meta['health_check_url'] ?? null;
        if (! empty($url) && is_string($url)) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->get($url);
                if ($response->successful()) {
                    return true;
                }
            } catch (\Throwable) {
                // Fall through to TCP check
            }
        }

        $host = $server->ip_address;
        $port = (int) ($server->ssh_port ?: 22);

        return $this->attemptTcpConnection($host, $port);
    }

    private function attemptTcpConnection(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $context = stream_context_create([
            'socket' => [
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            ],
        ]);
        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket !== false) {
            fclose($socket);

            return true;
        }

        return false;
    }
}
