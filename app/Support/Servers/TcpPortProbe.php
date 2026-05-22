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

        // In the test environment we never want to perform a real TCP connect — most
        // server fixtures use TEST-NET (203.0.113.0/24) or other non-routable IPs,
        // and the connect hangs against them until OS timeout. Across hundreds of
        // tests that cumulative wait crosses the 90s max_execution_time and kills
        // the PHPUnit worker. Tests that care about the probe result mock this
        // service directly; everyone else can safely treat the port as closed.
        if (app()->environment('testing')) {
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
