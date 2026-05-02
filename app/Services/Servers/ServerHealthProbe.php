<?php

namespace App\Services\Servers;

use App\Models\Server;
use Illuminate\Support\Facades\Http;

class ServerHealthProbe
{
    public const CONNECT_TIMEOUT_SECONDS = 4;

    public const HTTP_TIMEOUT_SECONDS = 5;

    /**
     * Probe a server synchronously. Tries an HTTP health check URL when one is
     * configured in meta['health_check_url'], then falls back to a TCP probe of
     * the SSH port. Returns a structured result for inline display.
     *
     * @return array{
     *     ok: bool,
     *     method: ?string,
     *     latency_ms: ?int,
     *     host: ?string,
     *     port: ?int,
     *     http_status: ?int,
     *     http_url: ?string,
     *     error: ?string,
     *     tested_at: string
     * }
     */
    public function probe(Server $server): array
    {
        $result = [
            'ok' => false,
            'method' => null,
            'latency_ms' => null,
            'host' => $server->ip_address ?: null,
            'port' => (int) ($server->ssh_port ?: 22),
            'http_status' => null,
            'http_url' => null,
            'error' => null,
            'tested_at' => now()->toIso8601String(),
        ];

        if (empty($server->ip_address)) {
            $result['error'] = 'Server has no IP address.';

            return $result;
        }

        $url = $server->meta['health_check_url'] ?? null;
        if (! empty($url) && is_string($url)) {
            $result['http_url'] = $url;
            $http = $this->probeHttp($url);
            $result['http_status'] = $http['status'];
            if ($http['ok']) {
                $result['ok'] = true;
                $result['method'] = 'http';
                $result['latency_ms'] = $http['latency_ms'];

                return $result;
            }
            $result['error'] = $http['error'];
        }

        $tcp = $this->probeTcp($server->ip_address, $result['port']);
        $result['method'] = $result['http_url'] ? 'http+tcp' : 'tcp';
        $result['ok'] = $tcp['ok'];
        if ($tcp['ok']) {
            $result['latency_ms'] = $tcp['latency_ms'];
            $result['error'] = null;
        } elseif ($result['error'] === null) {
            $result['error'] = $tcp['error'];
        }

        return $result;
    }

    /** @return array{ok: bool, status: ?int, latency_ms: ?int, error: ?string} */
    private function probeHttp(string $url): array
    {
        $start = microtime(true);
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->get($url);
            $latency = (int) round((microtime(true) - $start) * 1000);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'latency_ms' => $latency,
                'error' => $response->successful() ? null : 'HTTP '.$response->status(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'latency_ms' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @return array{ok: bool, latency_ms: ?int, error: ?string} */
    private function probeTcp(string $host, int $port): array
    {
        $errno = 0;
        $errstr = '';
        $context = stream_context_create([
            'socket' => ['connect_timeout' => self::CONNECT_TIMEOUT_SECONDS],
        ]);
        $start = microtime(true);
        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            return [
                'ok' => false,
                'latency_ms' => null,
                'error' => $errstr !== '' ? $errstr : 'TCP connection failed',
            ];
        }

        $latency = (int) round((microtime(true) - $start) * 1000);
        fclose($socket);

        return ['ok' => true, 'latency_ms' => $latency, 'error' => null];
    }
}
