<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Jobs\WaitForServerSshReadyJob;

/**
 * Lightweight TCP connect probe (same semantics as {@see WaitForServerSshReadyJob}).
 */
final class TcpPortProbe
{
    public static function isOpen(string $host, int $port, int $timeoutSeconds = 5): bool
    {
        $host = trim($host);
        if ($host === '' || $port < 1 || $port > 65535) {
            return false;
        }

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
